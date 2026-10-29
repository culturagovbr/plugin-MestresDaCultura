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

    // O festejo é um Evento do core, e a planilha dos Festejos não pede a descrição curta, a classificação etária, a
    // linguagem nem o valor de entrada da agenda (decisão FJD3). Só no subsite dos Mestres, como os campos do perfil acima.
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

        // A UF, a cidade e a região do festejo saem do primeiro local da agenda (FestivityPlace::sync): a cada
        // gravação do festejo, que o core repete a cada período, e quando o endereço de um local muda. Na mesma
        // gravação, libras e audiodescrição marcam os campos do core, o aceite ganha a data do servidor e a
        // instituição sai se o tipo de realizador não a tem.
        $app->hook('entity(Event).save:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::sync($this);
                Services\FestivityFields::syncCore($this);
                Services\FestivityFields::stampConsent($this);
                Services\FestivityFields::clearInstitution($this);
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
            if (Plugin::isTheme()) {
                Services\FestivityPlace::release($this);
            }
        });

        // Antes de apagar o festejo de vez, a situação dos locais é sincronizada sem ele (FestivityPlace::syncWithout).
        $app->hook('entity(Event).destroy:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::syncWithout($this);
            }
        });

        $app->hook('entity(Event).remove:after', function () {
            Services\FestivityPlace::forgetReleased();
        });

        // O local nasce em rascunho, é publicado com o festejo (FestivityPlace::syncStatus) e não tem a situação editada.
        $app->hook('entity(Space).insert:before', function () {
            if (Plugin::isTheme()) {
                $this->status = \MapasCulturais\Entities\Space::STATUS_DRAFT;
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

        // A exclusão de "Meus locais" não leva o local de um festejo; o erro diz quais o usam. A permissão é a do core,
        // conferida antes para não mostrar a outra pessoa os festejos do local.
        $app->hook('DELETE(space.single):before', function () {
            $space = $this->requestedEntity;

            if (!Plugin::isTheme() || !$space instanceof \MapasCulturais\Entities\Space || !$space->canUser('remove')) {
                return;
            }

            // Festejo na lixeira não impede a exclusão do local.
            if ($events = array_filter(Services\FestivityPlace::eventsAt($space->id), fn ($event) => $event->status >= \MapasCulturais\Entities\Event::STATUS_DRAFT)) {
                $names = implode(', ', array_map(fn ($event) => $event->name, $events));
                $this->errorJson(['space' => [sprintf(i::__('Este local está na agenda de: %s. Tire-o dos festejos antes de excluir.'), $names)]], 400);
            }
        });

        // O festejo copia do local só a UF e a cidade (FestivityPlace::sync): o resto não regrava os festejos dele. Os
        // metadados alterados seguem marcados até o fim da gravação.
        $app->hook('entity(Space).save:finish', function () use ($app) {
            if (!Plugin::isTheme() || !$this->id || Services\FestivityPlace::$syncing) {
                return;
            }

            if (!array_intersect_key($this->changedMetadata, array_flip(['En_Estado', 'En_Municipio']))) {
                return;
            }

            $app->disableAccessControl();

            try {
                foreach (Services\FestivityPlace::eventsAt($this->id) as $event) {
                    $event->save(true);
                }
            } finally {
                $app->enableAccessControl();
            }
        });
    }

    // O cadastro do festejo em 6 etapas (Controller::GET_festivity, Services\FestivityForm) em `/cadastrar-festejo/`.
    // No subsite dos Mestres todo evento é festejo: a criação e a edição do core levam ao formulário, e publicar, pelo
    // formulário ou pelo painel ("Meus eventos"), cobra as 6 etapas; o festejo publicado também não perde o que elas
    // cobram. O rascunho salva sem cobrar.
    // Tira a situação dos dados, em qualquer caixa de letra.
    private static function dropStatus(array &$data): void
    {
        foreach (array_keys($data) as $key) {
            if (is_string($key) && strcasecmp($key, 'status') === 0) {
                unset($data[$key]);
            }
        }
    }

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

        $app->hook('GET(event.edit):before', function () use ($app, $isFestivity) {
            $event = $this->requestedEntity;

            if ($event instanceof Event && $isFestivity($event)) {
                $app->redirect($app->createUrl('mdc', 'festivity', [$event->id]));
            }
        });

        // Na publicação, a validação cobra as 6 etapas com o evento ainda em rascunho.
        $publishing = false;
        $app->hook('ALL(event.publish):before', function () use (&$publishing) {
            $publishing = true;
        });

        $app->hook('entity(Event).validationErrors', function (array &$errors) use (&$publishing, $isFestivity) {
            if (!$isFestivity($this)) {
                return;
            }

            $errors += Services\FestivityForm::invalid($this);

            if ($publishing || $this->status > Event::STATUS_DRAFT) {
                $errors += Services\FestivityForm::missing($this);
            }
        });
    }

    // Busca de festejos em `/festejos-populares/`, com filtro por mês da agenda, e o caminho pela busca na página do
    // festejo.
    private static function openTheFestivitySearch(Plugin $plugin): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['festejos-populares'] = ['mdc', 'festivities'];

        $app->hook('ApiQuery(Event).params', function (&$params) {
            /** @var ApiQuery $this */
            if (!array_key_exists(Services\FestivitySearch::MONTH_PARAM, $params)) {
                return;
            }

            $month = (int) $params[Services\FestivitySearch::MONTH_PARAM];
            unset($params[Services\FestivitySearch::MONTH_PARAM]);

            if ($month >= 1 && $month <= 12) {
                $params['id'] = API::IN(Services\FestivitySearch::idsInMonth($month) ?: [0]);
            }
        });

        $app->hook('mapas.printJsObject:before', function () {
            $event = $this->controller->requestedEntity ?? null;

            if ($this->controller->id === 'event' && $this->controller->action === 'single' && $event instanceof Event && Plugin::isFestivity($event)) {
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
                $profile = $event instanceof Event && Plugin::isFestivity($event)
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
