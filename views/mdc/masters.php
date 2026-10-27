<?php
/**
 * Busca pública de Mestras e Mestres (Controller::GET_masters, `/mestras-e-mestres/`): o `search` do core com os
 * filtros e a lista do programa (mdc-masters-search).
 *
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-tab
    mc-tabs
    mdc-masters-search
    search
');

$this->breadcrumb = [
    ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
    ['label' => i::__('Mestras e Mestres')],
];
?>
<search page-title="<?= htmlspecialchars(i::__('Mestras e Mestres')) ?>" entity-type="agent" :initial-pseudo-query="{}">
    <template #default="{pseudoQuery}">
        <?php // As abas da busca do core, em que o filtro se apoia. ?>
        <mc-tabs class="search__tabs">
            <template #before-tablist>
                <label class="search__tabs--before"><?php i::_e('Visualizar como:') ?></label>
            </template>
            <mc-tab icon="list" label="<?php i::esc_attr_e('Lista') ?>" slug="list">
                <div class="search__tabs--list">
                    <mdc-masters-search :pseudo-query="pseudoQuery"></mdc-masters-search>
                </div>
            </mc-tab>
        </mc-tabs>
    </template>
</search>
