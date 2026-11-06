<?php

namespace MDC\Hooks;

use MDC\Entities;
use MDC\Plugin;
use MDC\Services;
use MapasCulturais\App;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

/**
 * Árvore de Atuação: o tipo da entidade da árvore no enum do banco e a página da curadoria no painel.
 */
final class Tree
{
    static function register(Plugin $plugin): void
    {
        self::registerTreeNodeObjectType($plugin);
        self::addTreeCurationPage($plugin);
    }

    // Registra `TreeNode` na lista PHP do enum `object_type`.
    private static function registerTreeNodeObjectType(Plugin $plugin): void
    {
        App::i()->hook('doctrine.emum(object_type).values', function (&$values) {
            $values['TreeNode'] = Entities\Node::class;
        });
    }

    // Curadoria da árvore: página no painel e item no grupo de administração, só para curadores e só no subsite dos
    // Mestres.
    private static function addTreeCurationPage(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('GET(panel.mdc-tree-curation)', function () use ($app) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            // Fora do subsite dos Mestres, a página não existe, nem pede login.
            if (!Plugin::isTheme()) {
                $app->pass();
            }

            $this->requireAuthentication();

            if (!Services\Curators::includes()) {
                throw new PermissionDenied($app->user, null, i::__('Curadoria da Árvore de Atuação'));
            }

            $app->view->enqueueScript('app-v2', 'mdc-tree-curation', 'js/tree-curation.js');

            $this->render('mdc-tree-curation');
        });

        $app->hook('panel.nav', function (&$nav) use ($app) {
            if (!Plugin::isTheme() || !isset($nav['admin'])) {
                return;
            }

            $nav['admin']['items'][] = [
                'route' => 'panel/mdc-tree-curation',
                'icon' => 'mdc-tree',
                'label' => i::__('Curadoria da Árvore de Atuação'),
                'condition' => fn () => Services\Curators::includes(),
            ];
        });

        $app->hook('component(mc-icon).iconset', function (&$iconset) {
            $iconset['mdc-tree'] = 'mdi:file-tree-outline';
            $iconset['mdc-zoom-in'] = 'mdi:plus';
            $iconset['mdc-zoom-out'] = 'mdi:minus';
            $iconset['mdc-not-proven'] = 'mdi:close-circle';
            $iconset['mdc-certificate'] = 'mdi:certificate';
        });
    }
}
