<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\i;

/**
 * Festejos do Calendário (Event no subsite dos Mestres): formulário em 6 etapas, campos dispensados, local, busca e
 * página do festejo.
 */
final class Festivities
{
    static function register(Plugin $plugin): void
    {
        self::skipEventFieldsTheFestejoDoesNotAsk($plugin);
        self::completeTheFestivityPlace($plugin);
        self::openTheFestivityForm($plugin);
        self::openTheFestivitySearch($plugin);
        self::showFestivityProfile($plugin);
    }

    // No subsite dos Mestres, o evento dispensa a descrição curta, a classificação etária, a linguagem e o valor de
    // entrada da agenda.
    private static function skipEventFieldsTheFestejoDoesNotAsk(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('entity(Event)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        // O período da agenda dispensa o valor de entrada.
        $app->hook('entity(EventOccurrence)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['price']['required']);
            }
        });

        // A descrição curta do evento também fica opcional nos metadados.
        $app->hook('entity(Event).propertiesMetadata', function (&$result) {
            if (Plugin::isTheme()) {
                $result['shortDescription']['required'] = false;
            }
        });

        $app->hook('app.register:after', function () use ($app) {
            if (!Plugin::isTheme()) {
                return;
            }

            if ($age = $app->getRegisteredMetadataByMetakey('classificacaoEtaria', Event::class)) {
                $age->is_required = false;
            }

            if ($language = $app->getRegisteredTaxonomies(Event::class)['linguagem'] ?? null) {
                $language->required = false;
            }
        });
    }

    // O local do festejo só pede o nome e o endereço: o tipo vem preenchido e a descrição curta não é obrigatória.
    private static function completeTheFestivityPlace(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('POST(space.index):data', function (&$data) use ($app) {
            if (!Plugin::isTheme() || !empty($data['type'])) {
                return;
            }

            $data['type'] = Services\FestivityPlace::type();

            if ($app->getRegisteredMetadataByMetakey(Services\FestivityPlace::OTHER_TYPE_FIELD, \MapasCulturais\Entities\Space::class)) {
                $data[Services\FestivityPlace::OTHER_TYPE_FIELD] ??= i::__('Local de festejo popular');
            }
        });

        $app->hook('entity(Space)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        // Em qualquer domínio, a gravação do festejo copia UF, cidade e região do primeiro local, limpa as respostas que
        // deixaram de valer, marca os campos do core, data o aceite e só publica com as 6 etapas.
        $app->hook('entity(Event).save:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::copyFirstPlace($this);
                Services\FestivityFields::clearUnanswered($this);
                Services\FestivityFields::syncCore($this);
                Services\FestivityFields::stampConsent($this);
                Services\FestivityForm::keepDraftIfIncomplete($this);
                Services\FestivityFields::markFirstPublished($this);
            }
        });

        // Depois da gravação, o selo dos festejos e a situação dos locais acompanham a publicação.
        $app->hook('entity(Event).save:finish', function ($flush = false) use ($plugin) {
            if (Plugin::isFestivity($this)) {
                $plugin->festivitySeal()->sync($this);

                // A situação dos locais só sincroniza na gravação com flush.
                if ($flush) {
                    Services\FestivityPlace::syncStatus($this);
                }
            }
        });

        $app->hook('entity(EventOccurrence).remove:before', function () {
            if ($this->event && Plugin::isFestivity($this->event)) {
                Services\FestivityPlace::release($this);
            }
        });

        // Antes de apagar o festejo de vez, a situação dos locais é sincronizada sem ele (FestivityPlace::syncWithout).
        $app->hook('entity(Event).destroy:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::syncWithout($this);
            }
        });

        // O período do festejo só fica num local do festejo (FestivityPlace::isOwnPlace), em qualquer domínio; a recusa é
        // na validação.
        $app->hook('entity(EventOccurrence).validationErrors', function (array &$errors) {
            if (!$this->event || !$this->space || !Plugin::isFestivity($this->event)) {
                return;
            }

            if (!Plugin::isFestivityPlace($this->space)) {
                $errors['space'] = [i::__('Escolha um local cadastrado no subsite dos Mestres.')];
            } elseif (!Services\FestivityPlace::isOwnPlace($this->event, $this->space)) {
                $errors['space'] = [i::__('Escolha um dos locais de quem cadastrou o festejo, ou cadastre um novo.')];
            }
        });

        // Mudar ou apagar o período é de quem altera o festejo, só num local do festejo; o último período do festejo
        // publicado não sai.
        $app->hook('entity(EventOccurrence).canUser(<<modify|remove>>)', function ($user, &$result) {
            if (!$this->event || !Plugin::isFestivity($this->event)) {
                return;
            }

            $result = $this->event->canUser('modify', $user)
                && ($this->space === null || Services\FestivityPlace::isOwnPlace($this->event, $this->space));
        });

        $app->hook('entity(EventOccurrence).canUser(remove)', function ($user, &$result) {
            if ($result && $this->event && Plugin::isFestivity($this->event) && Services\FestivityPlace::isLastOfPublished($this)) {
                $result = false;
            }
        });

        // O PATCH e o PUT não mudam o festejo nem o espaço do período do festejo.
        $app->hook('<<PATCH|PUT>>(eventoccurrence.single):data', function (&$data) use ($app) {
            $occurrence = $this->requestedEntity;
            $target = null;

            foreach ($data as $key => $value) {
                if (is_string($key) && strcasecmp($key, 'eventId') === 0) {
                    $target = $app->repo('Event')->find((int) $value);
                }
            }

            if (($occurrence?->event && Plugin::isFestivity($occurrence->event)) || ($target && Plugin::isFestivity($target))) {
                foreach (array_keys($data) as $key) {
                    if (is_string($key) && (strcasecmp($key, 'eventId') === 0 || strcasecmp($key, 'spaceId') === 0)) {
                        unset($data[$key]);
                    }
                }
            }
        });

        $app->hook('entity(Event).remove:after', function () {
            Services\FestivityPlace::forgetReleased();
        });

        // O local nasce em rascunho, é publicado com o festejo (FestivityPlace::syncStatus) e não tem a situação editada.
        $app->hook('entity(Space).insert:before', function () {
            if (Plugin::isFestivityPlace($this)) {
                $this->status = \MapasCulturais\Entities\Space::STATUS_DRAFT;
            }
        });

        // Em qualquer domínio e caminho, o local publicado por fora volta à situação gravada; só a sincronização o publica.
        $app->hook('entity(Space).save:before', function () use ($app) {
            if (!$this->id || Services\FestivityPlace::$syncing || !$app->isAccessControlEnabled() || !Plugin::isFestivityPlace($this)) {
                return;
            }

            $stored = (int) $app->conn->fetchOne('SELECT status FROM space WHERE id = :id', ['id' => $this->id]);

            if ((int) $this->status === \MapasCulturais\Entities\Space::STATUS_ENABLED && $stored !== \MapasCulturais\Entities\Space::STATUS_ENABLED) {
                $this->status = $stored;
            }
        });

        $app->hook('<<PATCH|PUT>>(space.single):data', function (&$data) {
            if (Plugin::isTheme()) {
                self::dropStatus($data);
            }
        });

        // O PATCH e o PUT do festejo também não mudam a situação; a data da primeira publicação nunca vem da requisição.
        $app->hook('<<PATCH|PUT>>(event.single):data', function (&$data) {
            if (Plugin::isTheme()) {
                self::dropStatus($data);
            }
        });

        $app->hook('<<PATCH|PUT>>(event.single):data, POST(event.index):data', function (&$data) {
            unset($data[Services\FestivityFields::FIRST_PUBLISHED]);
        });

        // Local na agenda de um festejo não é excluído, em qualquer domínio e rota; o erro, só para quem pode remover,
        // lista os festejos.
        $app->hook('DELETE(space.single):before, ALL(space.<<delete|destroy>>):before', function () {
            $space = $this->requestedEntity;

            if (!$space instanceof \MapasCulturais\Entities\Space || !Plugin::isFestivityPlace($space) || !$space->canUser('remove')) {
                return;
            }

            // Festejo na lixeira não impede a exclusão do local.
            if ($events = array_filter(Services\FestivityPlace::eventsAt($space->id), fn ($event) => $event->status >= \MapasCulturais\Entities\Event::STATUS_DRAFT && Plugin::isFestivity($event))) {
                $names = implode(', ', array_map(fn ($event) => $event->name, $events));
                $this->errorJson(['space' => [sprintf(i::__('Este local está na agenda de: %s. Tire-o dos festejos antes de excluir.'), $names)]], 400);
            }
        });

        // Mudar a UF ou a cidade do local regrava, em qualquer domínio, os festejos de quem é o local.
        $app->hook('entity(Space).save:finish', function () use ($app) {
            if (!$this->id || Services\FestivityPlace::$syncing || !Plugin::isFestivityPlace($this)) {
                return;
            }

            if (!array_intersect_key($this->changedMetadata, array_flip(['En_Estado', 'En_Municipio']))) {
                return;
            }

            $app->disableAccessControl();

            try {
                foreach (Services\FestivityPlace::eventsAt($this->id) as $event) {
                    if (Plugin::isFestivity($event) && Services\FestivityPlace::isOwnPlace($event, $this)) {
                        $event->save(true);
                    }
                }
            } finally {
                $app->enableAccessControl();
            }
        });
    }

    // Tira a situação dos dados, em qualquer caixa de letra.
    private static function dropStatus(array &$data): void
    {
        foreach (array_keys($data) as $key) {
            if (is_string($key) && strcasecmp($key, 'status') === 0) {
                unset($data[$key]);
            }
        }
    }

    // Cadastro do festejo em 6 etapas em `/cadastrar-festejo/`: criar e editar evento levam ao formulário, e o festejo
    // publicado, por qualquer caminho, cobra as 6 etapas; o rascunho salva sem cobrar.
    private static function openTheFestivityForm(Plugin $plugin): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['cadastrar-festejo'] = ['mdc', 'festivity'];
        // "Meus locais" (Controller::GET_places), no painel.
        $app->config['routes']['shortcuts']['meus-locais'] = ['mdc', 'places'];

        $isFestivity = fn (Event $event): bool => Plugin::isFestivity($event);

        $app->hook('GET(event.create):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->redirect($app->createUrl('mdc', 'festivity'));
            }
        });

        // O "Criar Evento" de "Meus eventos" abre o modal do core (create-event-festivity.js).
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'create-event' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-create-event-festivity', 'js/create-event-festivity.js', ['create-event']);
            }
        }, 1000);

        // No subsite dos Mestres, a edição do festejo leva ao formulário.
        $app->hook('GET(event.edit):before', function () use ($app, $isFestivity) {
            $event = $this->requestedEntity;

            if (Plugin::isTheme() && $event instanceof Event && $isFestivity($event)) {
                $app->redirect($app->createUrl('mdc', 'festivity', [$event->id]));
            }
        });

        // Na publicação, a validação cobra as 6 etapas com o evento ainda em rascunho.
        $publishing = false;
        $app->hook('ALL(event.publish):before', function () use (&$publishing) {
            $publishing = true;
        });

        // O dono de festejo publicado não fica sem o nome completo.
        $app->hook('entity(Agent).validationErrors', function (array &$errors) {
            if (Services\FestivityForm::losesResponsibleName($this)) {
                $errors['nomeCompleto'] = [i::__('O nome completo é obrigatório enquanto houver festejo publicado.')];
            }
        });

        // No festejo publicado, as etapas que faltam vão também nos campos enviados no PATCH.
        $sent = [];
        $app->hook('PATCH(event.single):data', function (&$data) use (&$sent) {
            $sent = array_keys($data);
        });

        $app->hook('entity(Event).validationErrors', function (array &$errors) use (&$publishing, &$sent, $isFestivity) {
            if (!$isFestivity($this)) {
                return;
            }

            $errors += Services\FestivityForm::invalid($this);

            if ($publishing || $this->status > Event::STATUS_DRAFT) {
                $missing = Services\FestivityForm::missing($this);
                $errors += $missing;

                foreach ($missing ? $sent : [] as $key) {
                    $errors[$key] ??= array_merge(...array_values($missing));
                }
            }
        });
    }

    // Busca de festejos em `/festejos-populares/`, com filtro por mês da agenda, e o caminho pela busca na página do
    // festejo.
    private static function openTheFestivitySearch(Plugin $plugin): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['festejos-populares'] = ['mdc', 'festivities'];

        // O mês e o "Com Mestras e Mestres da Rede" filtram por ids; com os dois, os festejos das duas listas.
        $app->hook('ApiQuery(Event).params', function (&$params) {
            /** @var ApiQuery $this */
            $lists = [];

            if (array_key_exists(Services\FestivitySearch::MONTH_PARAM, $params)) {
                $month = (int) $params[Services\FestivitySearch::MONTH_PARAM];
                unset($params[Services\FestivitySearch::MONTH_PARAM]);

                if ($month >= 1 && $month <= 12) {
                    $lists[] = Services\FestivitySearch::idsInMonth($month);
                }
            }

            if (array_key_exists(Services\FestivitySearch::MASTERS_PARAM, $params)) {
                unset($params[Services\FestivitySearch::MASTERS_PARAM]);
                $lists[] = Services\FestivitySearch::idsWithMasters();
            }

            if ($lists) {
                $params['id'] = API::IN(array_values(count($lists) > 1 ? array_intersect(...$lists) : $lists[0]) ?: [0]);
            }
        });

        $app->hook('mapas.printJsObject:before', function () {
            $event = $this->controller->requestedEntity ?? null;

            if (Plugin::isTheme() && $this->controller->id === 'event' && $this->controller->action === 'single' && $event instanceof Event && Plugin::isFestivity($event)) {
                $this->breadcrumb = Services\ProgramBreadcrumb::festivity((string) $event->name);
                if (isset($this->jsObject['breadcrumb'])) {
                    $this->jsObject['breadcrumb'] = $this->breadcrumb;
                }
            }
        });
    }

    // Página do festejo: as seções do programa nos ganchos da página de evento, montadas só com o que é público
    // (Services\FestivityProfile).
    private static function showFestivityProfile(Plugin $plugin): void
    {
        $app = App::i();
        $profile = false;

        $render = function (string $part) use ($plugin, &$profile) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $event = $this->controller->requestedEntity;

            if ($profile === false) {
                $profile = Plugin::isTheme() && $event instanceof Event && Plugin::isFestivity($event)
                    ? (new Services\FestivityProfile($plugin->festivitySeal(), $plugin->programSeal()))->of($event)
                    : null;
            }

            if (!$profile) {
                return;
            }

            $this->import('mdc-festivity-profile');
            $json = htmlspecialchars(json_encode($profile), ENT_QUOTES);

            echo "<mdc-festivity-profile part=\"{$part}\" :profile=\"{$json}\"></mdc-festivity-profile>";
        };

        $parts = [
            'entity-header):end' => 'header',
            'entity-gallery-video):before' => 'main',
            'entity-seals):before' => 'aside',
        ];

        foreach ($parts as $hook => $part) {
            $app->hook("template(event.single.{$hook}", function () use ($render, $part) {
                $render->call($this, $part);
            });
        }
    }
}
