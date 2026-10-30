<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Subsite dos Mestres isolado do resto da instalação: entidades desligadas, inscrições e notificações só do
 * programa, o programa no seu subsite e os selos que cada papel vê.
 */
final class SubsiteIsolation
{
    static function register(Plugin $plugin): void
    {
        self::blockDisabledEntities($plugin);
        self::scopeRegistrationsToFixedOpportunity($plugin);
        self::sendForeignRegistrationsHome($plugin);
        self::keepTheProgramOnItsSite($plugin);
        self::showOnlyTheProgramSeals($plugin);
        self::keepEntitiesInTheSubsite($plugin);
    }

    // Entidades desligadas no tema (config.php): agentes só para admins, projetos só para o saasSuperAdmin e espaços
    // para ninguém.
    private static function blockDisabledEntities(Plugin $plugin): void
    {
        $app = \MapasCulturais\App::i();

        $isAllowed = fn (string $entity): bool => $app->isEnabled($entity) || match ($entity) {
            'agents' => $app->user->is('admin'),
            'projects' => $app->user->is('saasSuperAdmin'),
            default => false,
        };

        $isOwnProfile = function (mixed $id) use ($app): bool {
            $profile = $app->user->profile ?? null;

            return $id && $profile && (int) $id === $profile->id;
        };

        // A página do agente com o selo do programa abre para todos.
        $isPublicMaster = fn (string $action, mixed $id): bool => $action === 'single' && $id && $plugin->programSeal()->isGivenTo((int) $id);

        // O próprio perfil também abre.
        $app->hook('GET(agent.<<*>>):before', function () use ($app, $isAllowed, $isOwnProfile, $isPublicMaster) {
            if ($isAllowed('agents') || $isOwnProfile($this->urlData['id'] ?? null) || $isPublicMaster($this->action, $this->urlData['id'] ?? null)) {
                return;
            }

            $app->pass();
        });

        // Quem tem conta grava o próprio local de festejo (Services\FestivityPlace).
        $isFestivityPlace = fn (string $controller, string $action): bool => $controller === 'space'
            && !$app->user->is('guest') && Services\FestivityPlace::isWrite($app->request->getMethod(), $action);

        // Fecha as rotas de espaço e de projeto em qualquer método HTTP; a API fica de fora.
        foreach (['space' => 'spaces', 'project' => 'projects'] as $controller => $entity) {
            $app->hook("ALL({$controller}.<<*>>):before", function () use ($app, $controller, $entity, $isAllowed, $isFestivityPlace) {
                if (!$isAllowed($entity) && !$isFestivityPlace($controller, $this->action)) {
                    $app->pass();
                }
            });
        }

        $app->hook('GET(<<search|panel>>.<<agents|spaces|projects>>):before', function () use ($app, $isAllowed) {
            if (!$isAllowed($this->action)) {
                $app->pass();
            }
        });
    }

    // No subsite dos Mestres, a API de inscrições só traz as da oportunidade do programa, para todos
    // (Services\RegistrationScope).
    private static function scopeRegistrationsToFixedOpportunity(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        $app->hook('ApiQuery(Registration).params', function (&$params) use ($app, $fixedOpportunity) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || !Plugin::isTheme()) {
                return;
            }

            // Sem oportunidade configurada, a consulta segue sem filtro.
            if (!$fixedOpportunity->isConfigured()) {
                return;
            }

            $allowed = $fixedOpportunity->ids();

            if ($allowed) {
                $params['opportunity'] = API::IN(Services\RegistrationScope::narrow($params['opportunity'] ?? null, $allowed));
            }
        });
    }

    // No subsite dos Mestres, a inscrição de outro edital abre no subsite dele; se for este mesmo, não abre.
    private static function sendForeignRegistrationsHome(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        $app->hook('GET(registration.<<*>>):before', function () use ($app, $fixedOpportunity) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            if (!Plugin::isTheme() || !$fixedOpportunity->isConfigured()) {
                return;
            }

            $registration = $this->requestedEntity;

            if (!$registration instanceof Registration || $fixedOpportunity->includes($registration)) {
                return;
            }

            $origin = $registration->opportunity?->subsite;

            if (!$registration->opportunity || Services\SubsiteRedirect::isCurrent($origin)) {
                $app->pass();
            }

            $app->redirect(Services\SubsiteRedirect::urlOn($origin, $this));
        });
    }

    // Fora do subsite dos Mestres, a oportunidade do programa sai da busca e da home, e as páginas dela e das inscrições
    // dela vão para o subsite dos Mestres.
    private static function keepTheProgramOnItsSite(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();
        $elsewhere = fn (): bool => $fixedOpportunity->isConfigured()
            && ($fixed = $fixedOpportunity->get())
            && !Services\SubsiteRedirect::isCurrent($fixed->subsite);

        $app->hook('ApiQuery(Opportunity).params', function (&$params) use ($app, $fixedOpportunity, $elsewhere) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || !$app->request?->isAjax() || !$elsewhere()) {
                return;
            }

            $referer = $app->request->getReferer();
            $referer = strtok(is_array($referer) ? ($referer[0] ?? '') : (string) $referer, '?#');

            if (!in_array($referer, [$app->createUrl('site', 'index'), $app->createUrl('search', 'opportunities')], true)) {
                return;
            }

            $hidden = API::NOT_IN($fixedOpportunity->ids());
            $params['id'] = isset($params['id']) ? API::AND($params['id'], $hidden) : $hidden;
        });

        $app->hook('GET(<<opportunity|registration>>.<<*>>):before', function () use ($app, $fixedOpportunity, $elsewhere) {
            $entity = $this->requestedEntity;
            $opportunity = $entity instanceof Registration ? $entity->opportunity : $entity;

            if (!$opportunity instanceof Opportunity || !$fixedOpportunity->matches($opportunity) || !$elsewhere()) {
                return;
            }

            $app->redirect(Services\SubsiteRedirect::urlOn($fixedOpportunity->get()->subsite, $this));
        });
    }

    // No subsite dos Mestres, quem não é admin só vê os selos do programa, e o gestor e o saasSuperAdmin só os do
    // subsite: na API de selos, nos selos dos agentes e nas páginas de selo e de certificado.
    private static function showOnlyTheProgramSeals(Plugin $plugin): void
    {
        $app = App::i();
        $visibility = new Services\SealVisibility($plugin->programSeal(), $plugin->festivitySeal(), $plugin->fixedOpportunity());

        // Os ids que a pessoa vê; null, sem filtro (fora do subsite, ou sem o selo do programa configurado).
        $visible = fn (): ?array => Plugin::isTheme() ? $visibility->visibleTo($app->user) : null;

        $app->hook('ApiQuery(Seal).params', function (&$params) use ($visible) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || ($ids = $visible()) === null) {
                return;
            }

            $only = API::IN($ids ?: [0]);
            $params['id'] = isset($params['id']) ? API::AND($params['id'], $only) : $only;
        });

        $app->hook('API.<<find|findOne>>(agent).result', function ($params, &$result) use ($visible) {
            if (!is_array($result) || ($ids = $visible()) === null) {
                return;
            }

            if (isset($result['id'])) {
                $one = [&$result];
                Services\SealVisibility::keepVisible($one, $ids);
            } else {
                Services\SealVisibility::keepVisible($result, $ids);
            }
        });

        // Os selos do agente também saem filtrados do JSON da página do perfil.
        $app->hook('view.requestedEntity(Agent).result', function (&$entity) use ($visible) {
            if (($ids = $visible()) !== null) {
                $one = [&$entity];
                Services\SealVisibility::keepVisible($one, $ids);
            }
        });

        $app->hook('GET(seal.<<single|sealRelation|printsealrelation>>):before', function () use ($app, $visible) {
            if (($ids = $visible()) === null) {
                return;
            }

            $id = (int) ($this->data['id'] ?? 0);
            $seal = $this->action === 'single' ? $app->repo('Seal')->find($id) : $app->repo('SealRelation')->find($id)?->seal;

            if (!$seal || !in_array($seal->id, $ids, true)) {
                $app->pass();
            }
        });
    }

    // No subsite dos Mestres, espaços e períodos da agenda só vêm do subsite atual; os eventos de outro subsite abrem
    // no subsite deles.
    private static function keepEntitiesInTheSubsite(Plugin $plugin): void
    {
        $app = App::i();
        $current = fn () => $app->subsite?->id ? API::EQ($app->subsite->id) : API::NULL();

        $app->hook('ApiQuery(space).params', function (&$params) use ($current) {
            if (Plugin::isTheme()) {
                $params['_subsiteId'] = $current();
            }
        });

        // A agenda de um festejo que a pessoa pode ver (event: EQ(id)) vem inteira, rascunho incluído; fora isso, só a
        // dos festejos publicados do subsite.
        $app->hook('ApiQuery(eventoccurrence).params', function (&$params) use ($app, $current) {
            /** @var ApiQuery $this */
            if (!Plugin::isTheme() || $this->parentQuery) {
                return;
            }

            if (preg_match('/^EQ\((\d+)\)$/', (string) ($params['event'] ?? ''), $match)) {
                $event = $app->repo('Event')->find((int) $match[1]);

                if ($event && Plugin::isFestivity($event) && $event->canUser('view')) {
                    return;
                }
            }

            $this->addFilterByApiQuery(new ApiQuery(Event::class, ['_subsiteId' => $current(), 'status' => API::EQ(Event::STATUS_ENABLED)]), 'id', 'event');
        });

        // O evento de outro subsite abre no subsite dele.
        $app->hook('GET(event.<<single|edit>>):before', function () use ($app) {
            $event = $this->requestedEntity;

            if (Plugin::isTheme() && $event instanceof Event && $event->id && !Services\SubsiteRedirect::isCurrent($event->subsite)) {
                $app->redirect(Services\SubsiteRedirect::urlOn($event->subsite, $this));
            }
        });

        // A busca de eventos leva à de festejos.
        $app->hook('GET(search.events):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->redirect($app->createUrl('mdc', 'festivities'));
            }
        });
    }
}
