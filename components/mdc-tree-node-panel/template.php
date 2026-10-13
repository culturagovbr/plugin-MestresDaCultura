<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<div class="mdc-tree-node-panel">
    <div class="mdc-tree-node-panel__head">
        <p class="mdc-tree-node-panel__path">{{path.slice(0, -1).join(' › ') || (isRoot ? '' : text('raiz'))}}</p>
        <h2 class="mdc-tree-node-panel__name">{{node.name}}</h2>
        <div class="mdc-tree-node-panel__chips">
            <span class="mdc-tree-node-panel__chip">{{level}}</span>
            <span v-if="!isRoot" class="mdc-tree-node-panel__chip" :class="node.enabled ? 'mdc-tree-node-panel__chip--on' : 'mdc-tree-node-panel__chip--off'">{{node.enabled ? text('ativo') : text('desativado')}}</span>
        </div>
    </div>

    <div class="mdc-tree-node-panel__stats">
        <div v-if="!isRoot" class="mdc-tree-node-panel__stat"><strong>{{node.sent}}</strong><span>{{text('enviados')}}</span></div>
        <div v-if="!isRoot" class="mdc-tree-node-panel__stat"><strong>{{node.drafts}}</strong><span>{{text('rascunhos')}}</span></div>
        <div class="mdc-tree-node-panel__stat"><strong>{{branch.active}}</strong><span>{{branch.active === branch.all ? text('no ramo') : format('no ramo de', branch.all)}}</span></div>
    </div>

    <div v-if="!isRoot && node.enabled" class="mdc-tree-node-panel__order">
        <button type="button" class="button button--sm button--primary-outline" :disabled="busy || position <= 0" @click="$emit('reorder', -1)">{{text('subir')}}</button>
        <button type="button" class="button button--sm button--primary-outline" :disabled="busy || position >= siblings.length - 1" @click="$emit('reorder', 1)">{{text('descer')}}</button>
        <span>{{format('posicao', position + 1, siblings.length, parent.name)}}</span>
    </div>

    <div class="mdc-tree-node-panel__children">
        <h3>{{format(isRoot ? 'segmentos' : 'abaixo', node.children.length)}}</h3>
        <p v-if="!node.children.length" class="mdc-tree-node-panel__empty">{{text('nada abaixo')}}</p>
        <ul v-else>
            <li v-for="child in node.children" :key="child.id">
                <button type="button" class="mdc-tree-node-panel__child" :class="{ 'mdc-tree-node-panel__child--off': !child.enabled }" @click="$emit('select', child.id)">
                    <span>{{child.name}}<small v-if="child.children.length"> · {{format('itens', child.children.length)}}</small></span>
                    <small :title="text('enviados')">{{child.sent}}</small>
                </button>
            </li>
        </ul>
    </div>
</div>
