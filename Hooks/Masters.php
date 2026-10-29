<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\API;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\i;

/**
 * Mestras e mestres: perfil (campos dispensados, comunidades tradicionais, nome do próprio perfil), busca, vitrine
 * da home e página pública do mestre aprovado.
 */
final class Masters
{
    static function register(Plugin $plugin): void
    {
        self::addTraditionalCommunities($plugin);
        self::nameTheOwnProfile($plugin);
        self::feedTheHomeShowcase($plugin);
        self::openTheMastersSearch($plugin);
        self::skipProfileFieldsTheProgramDoesNotAsk($plugin);
        self::showMasterProfile($plugin);
    }

    // Em todo o Mapa, as definições de `comunidadesTradicional` do agente ganham as comunidades do programa, depois
    // de todos os registros.
    private static function addTraditionalCommunities(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('app.register:after', function () use ($app) {
            $definitions = [];

            foreach ([null, ...$app->getRegisteredEntityTypes(Agent::class)] as $type) {
                $definition = $app->getRegisteredMetadataByMetakey('comunidadesTradicional', Agent::class, $type?->id);

                if ($definition) {
                    $definitions[spl_object_id($definition)] = $definition;
                }
            }

            foreach ($definitions as $definition) {
                $definition->options = Plugin::withTraditionalCommunities($definition->options);
            }
        });
    }

    // Para quem segue o vocabulário do programa, o próprio agente é "perfil" no caminho, no botão e na edição; o perfil
    // público da mestra com o selo ganha o caminho pela busca.
    private static function nameTheOwnProfile(Plugin $plugin): void
    {
        $app = App::i();

        $isOwnProfile = function ($view) use ($app): bool {
            $agent = $view->controller->requestedEntity ?? null;

            return Plugin::isTheme() && $view->controller->id === 'agent' && $agent instanceof Agent
                && !$app->user->is('guest') && Services\ProgramVocabulary::appliesTo($app->user) && $agent->id === $app->user->profile?->id;
        };

        $app->hook('mapas.printJsObject:before', function () use ($isOwnProfile, $plugin) {
            $agent = $this->controller->requestedEntity ?? null;

            if ($isOwnProfile($this) && in_array($this->controller->action, ['single', 'edit'], true)) {
                $breadcrumb = Services\ProgramBreadcrumb::profile($agent->singleUrl, $this->controller->action === 'edit');
            } elseif (Plugin::isTheme() && $this->controller->id === 'agent' && $this->controller->action === 'single'
                && $agent instanceof Agent && $agent->id && $plugin->programSeal()->isGivenTo($agent->id)) {
                // O perfil público da mestra, para quem não é ela (ver ProgramBreadcrumb::master).
                $breadcrumb = Services\ProgramBreadcrumb::master($agent->{Services\MasterPublicData::KNOWN_AS} ?: $agent->name);
            } else {
                return;
            }

            $this->breadcrumb = $breadcrumb;

            if (isset($this->jsObject['breadcrumb'])) {
                $this->jsObject['breadcrumb'] = $breadcrumb;
            }
        });

        $app->hook('component(agent.single.entity-actions).texts', function (array &$texts) use ($isOwnProfile) {
            if ($isOwnProfile($this)) {
                $texts['Agente'] = i::__('perfil');
            }
        });

        $app->hook('component(agent.edit.entity-header).texts', function (array &$texts) use ($isOwnProfile) {
            if ($isOwnProfile($this)) {
                $texts['title agent-1'] = i::__('Edição do perfil');
            }
        });
    }

    // Dados da vitrine da home e do mapa da Rede no objeto da página (Services\MasterShowcase).
    private static function feedTheHomeShowcase(Plugin $plugin): void
    {
        App::i()->hook('view.render(site/index):before', function () {
            if (Plugin::isTheme()) {
                $showcase = new Services\MasterShowcase;
                $this->jsObject['config']['mdcShowcase'] = [
                    'highlights' => $showcase->highlights(),
                    // Os festejos publicados entram nos contadores da Rede.
                    'counts' => $showcase->counts() + ['festivities' => (new Services\FestivitySeal)->countPublished()],
                    'states' => (object) $showcase->byState(),
                    'map' => json_decode((string) file_get_contents(__DIR__ . '/data/br-uf.json'), true),
                    'searchUrl' => App::i()->createUrl('mdc', 'masters'),
                ];
            }
        });
    }

    // Busca de Mestras e Mestres em `/mestras-e-mestres/`; a busca por nome procura também o nome artístico.
    private static function openTheMastersSearch(Plugin $plugin): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['mestras-e-mestres'] = ['mdc', 'masters'];

        $app->hook('repo(agent).getIdsByKeywordDQL.join', function (&$joins) {
            if (Plugin::isTheme()) {
                $joins .= "\n LEFT JOIN e.__metadata mdc_known_as WITH mdc_known_as.key = '" . Services\MasterPublicData::KNOWN_AS . "'";
            }
        });

        $app->hook('repo(agent).getIdsByKeywordDQL.where', function (&$where, $keyword, $alias) {
            if (Plugin::isTheme()) {
                $where .= " OR unaccent(lower(mdc_known_as.value)) LIKE unaccent(lower(:{$alias}))";
            }
        });
    }

    // No subsite dos Mestres, a descrição curta e a área de atuação do agente não são obrigatórias, na validação e na
    // taxonomia.
    private static function skipProfileFieldsTheProgramDoesNotAsk(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('entity(Agent)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        $app->hook('app.register:after', function () use ($app) {
            if (Plugin::isTheme() && ($area = $app->getRegisteredTaxonomies(\MapasCulturais\Entities\Agent::class)['area'] ?? null)) {
                $area->required = false;
            }
        });

        // Na tela de criar conta, as duas também ficam opcionais (create-account-optional.js).
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'create-account' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-create-account-optional', 'js/create-account-optional.js', ['create-account']);
            }
        }, 1000);
    }

    // Perfil público do mestre aprovado nos ganchos da página de agente: cabeçalho, certificado, atuação cultural e
    // festejos (Services\MasterProfile).
    private static function showMasterProfile(Plugin $plugin): void
    {
        $app = App::i();
        $profile = false;

        $render = function (string $part) use ($app, $plugin, &$profile) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $agent = $this->controller->requestedEntity;

            if ($profile === false) {
                $profile = $agent instanceof \MapasCulturais\Entities\Agent
                    ? (new Services\MasterProfile($plugin->fixedOpportunity(), $plugin->programSeal()))->of($agent)
                    : null;
            }

            if (!$profile) {
                return;
            }

            $this->import('mdc-master-profile');
            $json = htmlspecialchars(json_encode($profile), ENT_QUOTES);

            echo "<mdc-master-profile part=\"{$part}\" :profile=\"{$json}\"></mdc-master-profile>";
        };

        // Os festejos da mestra vêm logo depois da atuação.
        $parts = [
            'entity-header):end' => ['header'],
            'single1-entity-info-taxonomie-area):before' => ['certificate'],
            'entity-gallery-video):before' => ['acting', 'festivities'],
        ];

        foreach ($parts as $hook => $hookParts) {
            $app->hook("template(agent.single.{$hook}", function () use ($render, $hookParts) {
                foreach ($hookParts as $part) {
                    $render->call($this, $part);
                }
            });
        }
    }
}
