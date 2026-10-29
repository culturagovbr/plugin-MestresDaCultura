<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\App;
use MapasCulturais\i;

/**
 * Painel no subsite dos Mestres: atalhos da gestão e da moderação no menu, blocos de cada papel, a mestra levada
 * ao painel e só as telas do programa abertas para quem não é admin.
 */
final class PanelMenu
{
    /**
     * Telas do painel que abrem para quem não é admin, com todo item do menu da mestra; as que não abrem para ninguém
     * ficam em Plugin::UNUSED_PANEL_SCREENS.
     */
    const OPEN_SCREENS = [
        'index', 'my-account', 'deleteaccount', 'terms', 'requireauth', 'registrations', 'evaluations', 'events',
        'mdc-tree-curation', 'mdc-committee',
    ];

    static function register(Plugin $plugin): void
    {
        self::addManagementShortcuts($plugin);
        self::addModerationShortcut($plugin);
        self::closeOtherPanelScreens($plugin);
        self::showRoleBlocksInThePanel($plugin);
        self::sendTheMasterToThePanel($plugin);
    }

    // Quem controla a oportunidade do programa ganha no menu do painel atalhos para os cadastros, as avaliações e a
    // comissão.
    private static function addManagementShortcuts(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();
        $controls = fn (): bool => $fixedOpportunity->isConfigured() && (bool) $fixedOpportunity->get()?->canUser('@control');

        $app->hook('panel.nav', function (&$nav) use ($controls, $fixedOpportunity) {
            if (!Plugin::isTheme() || !$controls()) {
                return;
            }

            $group = ['mdc-gestao' => ['label' => i::__('Gestão do cadastro'), 'column' => 'left', 'items' => Services\PanelSummary::shortcuts($fixedOpportunity->get())]];
            // Depois dos festejos, o último grupo do programa no menu do tema.
            $position = array_search('mdc-festejos', array_keys($nav), true);
            $nav = $position === false ? $group + $nav : array_slice($nav, 0, $position + 1, true) + $group + $nav;
        }, 1001);

        // O atalho da comissão leva à aba de fases da edição.
        $app->hook('GET(panel.mdc-committee)', function () use ($app, $controls, $fixedOpportunity) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!Plugin::isTheme() || !$controls()) {
                $app->pass();
            }

            $app->redirect($app->createUrl('opportunity', 'edit', [$fixedOpportunity->get()->id]) . '#config');
        });
    }

    // No subsite dos Mestres, "Minhas avaliações" leva o moderador ativo à lista da moderação e some para os demais;
    // o saasSuperAdmin mantém o menu do core.
    private static function addModerationShortcut(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        $app->hook('panel.nav', function (&$nav) use ($app, $fixedOpportunity) {
            if (!Plugin::isTheme() || $app->user->is('saasSuperAdmin')) {
                return;
            }

            $phase = $fixedOpportunity->isConfigured() ? $fixedOpportunity->moderatedBy($app->user) : null;
            $moderation = $phase ? ['route' => 'opportunity/userEvaluations', 'params' => [$phase->id], 'icon' => 'opportunity', 'label' => i::__('Moderação de cadastros')] : null;

            foreach ($nav as &$group) {
                if (isset($group['items'])) {
                    $items = array_map(fn ($item) => ($item['route'] ?? '') === 'panel/evaluations' ? $moderation : $item, $group['items']);
                    $group['items'] = array_values(array_filter($items));
                }
            }
            unset($group);
        }, 1001);
    }

    // No subsite, quem não é admin só abre as telas do programa; as não usadas, e o controlador `app`, não abrem para
    // ninguém.
    private static function closeOtherPanelScreens(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('GET(panel.<<*>>):before', function () use ($app) {
            $action = strtolower($this->action);

            if (Plugin::isTheme() && in_array($action, Plugin::UNUSED_PANEL_SCREENS, true)) {
                $app->pass();
            }

            if (Plugin::appliesToCurrentUser() && !in_array($action, self::OPEN_SCREENS, true)) {
                $app->pass();
            }
        });

        $app->hook('ALL(app.<<*>>):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->pass();
            }
        });
    }

    // Blocos de cada papel no painel, antes de "Meus cadastros" (Services\PanelSummary).
    private static function showRoleBlocksInThePanel(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('template(panel.index.mdc-role-blocks):before', function () use ($app, $plugin) {
            $summary = (new Services\PanelSummary($plugin->fixedOpportunity()))->for($app->user);

            if (!$summary['moderation'] && !$summary['management']) {
                return;
            }

            $this->import('mdc-panel-summary');
            $json = htmlspecialchars(json_encode($summary), ENT_QUOTES);

            echo "<mdc-panel-summary :summary=\"{$json}\"></mdc-panel-summary>";
        });
    }

    // Quem não controla a oportunidade do programa vai da página dela para o painel; o gestor e o saasSuperAdmin
    // veem a página.
    private static function sendTheMasterToThePanel(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        $app->hook('GET(opportunity.single):before', function () use ($app, $fixedOpportunity) {
            $opportunity = $this->requestedEntity;

            // Aberta em outro subsite, quem leva ao subsite dos Mestres é SubsiteIsolation::keepTheProgramOnItsSite.
            if (Plugin::isTheme() && $fixedOpportunity->isConfigured() && $fixedOpportunity->matches($opportunity)
                && Services\SubsiteRedirect::isCurrent($opportunity->subsite) && !$opportunity->canUser('@control')) {
                $app->redirect($app->createUrl('panel', 'index'));
            }
        });
    }
}
