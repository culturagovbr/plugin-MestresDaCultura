<?php
/**
 * Busca pública de festejos (Controller::GET_festivities, `/festejos-populares/`): o `search` do core com os filtros
 * e a lista do programa (mdc-festivities-search).
 *
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-tab
    mc-tabs
    mdc-festivities-search
    search
');

$this->breadcrumb = [
    ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
    ['label' => i::__('Festejos Populares')],
];
?>
<search page-title="<?= htmlspecialchars(i::__('Calendário de Festejos Populares do Brasil')) ?>" entity-type="event" :initial-pseudo-query="{}">
    <template #default="{pseudoQuery}">
        <mc-tabs class="search__tabs">
            <template #before-tablist>
                <label class="search__tabs--before"><?php i::_e('Visualizar como:') ?></label>
            </template>
            <mc-tab icon="list" label="<?php i::esc_attr_e('Lista') ?>" slug="list">
                <div class="search__tabs--list">
                    <mdc-festivities-search :pseudo-query="pseudoQuery"></mdc-festivities-search>
                </div>
            </mc-tab>
        </mc-tabs>
    </template>
</search>
