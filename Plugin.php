<?php

namespace MDC;

/**
 * O namespace é `MDC` porque o autoloader registra temas antes de plugins num mapa plano de
 * namespace para diretório: repetindo `MestresDaCultura`, o plugin seria ignorado sem erro.
 * Pelo mesmo motivo, nenhuma classe ou subpasta daqui pode conter `MDC` no nome.
 */
class Plugin extends \MapasCulturais\Plugin
{
    function register()
    {
    }

    function _init()
    {
        $this->blockDisabledEntities();
        $this->blockOpportunityBrowsing();
    }

    private function blockOpportunityBrowsing(): void
    {
        $app = \MapasCulturais\App::i();

        $isTheme = fn (): bool => $app->view instanceof \MestresDaCultura\Theme;

        // O plugin roda na instalação inteira; a oportunidade do programa nasce em outro subsite.
        $app->hook('entity(Opportunity).canUser(create)', function ($user, &$result) use ($isTheme) {
            if ($isTheme() && !$user->is('saasSuperAdmin')) {
                $result = false;
            }
        });

        // O tema roda sobre uma oportunidade fixa: não há o que listar.
        $app->hook('GET(<<search|panel>>.opportunities):before', function () use ($app, $isTheme) {
            if ($isTheme() && !$app->user->is('admin')) {
                $app->pass();
            }
        });
    }

    private function blockDisabledEntities(): void
    {
        $app = \MapasCulturais\App::i();

        $isAllowed = fn (string $entity): bool => $app->isEnabled($entity) || $app->user->is('admin');

        $isOwnProfile = function (mixed $id) use ($app): bool {
            $profile = $app->user->profile ?? null;

            return $id && $profile && (int) $id === $profile->id;
        };

        // O próprio perfil fica de fora: toda inscrição pertence a um agente.
        $app->hook('GET(agent.<<*>>):before', function () use ($app, $isAllowed, $isOwnProfile) {
            if ($isAllowed('agents') || $isOwnProfile($this->urlData['id'] ?? null)) {
                return;
            }

            $app->pass();
        });

        // ALL alcança todo método HTTP e nunca a API, que gera o prefixo API(...).
        foreach (['space' => 'spaces', 'project' => 'projects'] as $controller => $entity) {
            $app->hook("ALL({$controller}.<<*>>):before", function () use ($app, $entity, $isAllowed) {
                if (!$isAllowed($entity)) {
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
}
