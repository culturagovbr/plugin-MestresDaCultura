<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-alert
    mc-loading
    mc-tab
    mc-tabs
    mdc-tree-list
    mdc-tree-map
    mdc-tree-node-panel
    mdc-tree-others
    mdc-tree-search
');
?>
<div class="mdc-tree-curation">
    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha')}}</mc-alert>

    <mc-tabs v-if="tree" ref="tabs" class="mdc-tree-curation__tabs">
        <mc-tab :label="text('aba mapa')" slug="mapa" cache>
            <div class="mdc-tree-curation__toolbar">
                <mdc-tree-search :tree="tree" @select="focus" @matches="matches = $event"></mdc-tree-search>
                <label class="mdc-tree-curation__check"><input type="checkbox" v-model="showDisabled"> {{text('mostrar desativados')}}</label>
                <label class="mdc-tree-curation__check"><input type="checkbox" v-model="showDuplicates"> {{text('destacar duplicatas')}}</label>
                <button type="button" class="button button--sm button--primary-outline" :aria-pressed="view === 'lista'" @click="view = view === 'mapa' ? 'lista' : 'mapa'">{{view === 'mapa' ? text('ver lista') : text('ver mapa')}}</button>
                <template v-if="view === 'mapa'">
                    <button type="button" class="button button--sm button--primary-outline" :disabled="!current || !current.node.children.length" @click="$refs.map.openBranch(selected)">{{text('abrir ramo')}}</button>
                    <button type="button" class="button button--sm button--text" @click="$refs.map.closeAll()">{{text('fechar tudo')}}</button>
                    <button type="button" class="button button--sm button--text" @click="$refs.map.fit()">{{text('enquadrar')}}</button>
                </template>
                <a class="button button--sm button--text" :href="exportUrl" download="atuacao.json" :title="text('exportar dica')">{{text('exportar')}}</a>
            </div>

            <div class="mdc-tree-curation__work">
                <mdc-tree-map v-if="view === 'mapa'" ref="map" :tree="tree" :selected="selected" :show-disabled="showDisabled" :show-duplicates="showDuplicates" :highlight="matches" @select="selected = $event"></mdc-tree-map>
                <mdc-tree-list v-else ref="list" :tree="tree" :selected="selected" :show-disabled="showDisabled" :show-duplicates="showDuplicates" :highlight="matches" @select="selected = $event"></mdc-tree-list>

                <aside class="mdc-tree-curation__panel" aria-live="polite">
                    <p v-if="!current" class="mdc-tree-curation__hint">{{text('escolha')}}</p>
                    <mdc-tree-node-panel v-else :node="current.node" :parent="current.parent" :path="current.path" :busy="busy" @select="focus"></mdc-tree-node-panel>
                </aside>
            </div>
        </mc-tab>

        <mc-tab :label="format('aba fila', pendingOthers)" slug="nao-encontrou" cache>
            <mdc-tree-others @count="pendingOthers = $event"></mdc-tree-others>
        </mc-tab>
    </mc-tabs>
</div>
