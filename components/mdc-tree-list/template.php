<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<div class="mdc-tree-list">
    <ul class="mdc-tree-list__tree" role="tree" :aria-label="text('arvore')">
        <li v-for="row in rows" :key="row.node.id" :ref="'item-' + row.node.id" class="mdc-tree-list__item" role="treeitem"
            :class="{ 'mdc-tree-list__item--off': !row.node.enabled, 'mdc-tree-list__item--selected': row.node.id === selected, 'mdc-tree-list__item--match': highlight.includes(row.node.id) }"
            :style="{ '--level': row.level }" :aria-level="row.level" :aria-selected="row.node.id === selected" :aria-expanded="kids(row.node).length ? expanded.has(row.node.id) : null"
            :tabindex="row.node.id === current ? 0 : -1" @keydown="keydown($event, row.node)" @click="choose(row.node)" @focus="focused = row.node.id">
            <button v-if="kids(row.node).length" type="button" class="mdc-tree-list__toggle" tabindex="-1" :aria-label="expanded.has(row.node.id) ? text('fechar') : format('abrir', kids(row.node).length)" @click.stop="toggle(row.node)">{{expanded.has(row.node.id) ? '−' : '+'}}</button>
            <span v-else class="mdc-tree-list__toggle mdc-tree-list__toggle--leaf" aria-hidden="true"></span>
            <span class="mdc-tree-list__name">{{row.node.name}}</span>
            <span v-if="!row.node.enabled" class="mdc-tree-list__tag">{{text('desativado')}}</span>
            <span v-if="showDuplicates && row.node.duplicates?.length" class="mdc-tree-list__tag mdc-tree-list__tag--duplicate">{{text('duplicata')}}</span>
            <span class="mdc-tree-list__count" :title="text('enviados')">{{row.node.sent}}</span>
        </li>
    </ul>
</div>
