<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-alert
    mc-loading
    mdc-tree-map
    mdc-tree-node-panel
    mdc-tree-search
');
?>
<div class="mdc-tree-curation">
    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha')}}</mc-alert>

    <template v-if="tree">
        <div class="mdc-tree-curation__toolbar">
            <mdc-tree-search :tree="tree" @select="focus" @matches="matches = $event"></mdc-tree-search>
            <label class="mdc-tree-curation__check"><input type="checkbox" v-model="showDisabled"> {{text('mostrar desativados')}}</label>
            <button type="button" class="button button--sm button--primary-outline" :disabled="!current || !current.node.children.length" @click="$refs.map.openBranch(selected)">{{text('abrir ramo')}}</button>
            <button type="button" class="button button--sm button--text" @click="$refs.map.closeAll()">{{text('fechar tudo')}}</button>
            <button type="button" class="button button--sm button--text" @click="$refs.map.fit()">{{text('enquadrar')}}</button>
        </div>

        <div class="mdc-tree-curation__work">
            <mdc-tree-map ref="map" :tree="tree" :selected="selected" :show-disabled="showDisabled" :highlight="matches" @select="selected = $event"></mdc-tree-map>

            <aside class="mdc-tree-curation__panel" aria-live="polite">
                <p v-if="!current" class="mdc-tree-curation__hint">{{text('escolha')}}</p>
                <mdc-tree-node-panel v-else :node="current.node" :parent="current.parent" :path="current.path" :busy="busy" @select="focus" @reorder="reorder"></mdc-tree-node-panel>
            </aside>
        </div>
    </template>
</div>
