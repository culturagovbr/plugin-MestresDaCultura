<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-modal
');
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

    <div class="mdc-tree-node-panel__actions">
        <mc-modal v-if="!isRoot && node.enabled" :title="text('renomear')" @open="newName = node.name">
            <template #default>
                <div class="field">
                    <label for="mdc-tree-novo-nome">{{text('nome')}}</label>
                    <input id="mdc-tree-novo-nome" v-model="newName" type="text" maxlength="255">
                </div>
                <p class="mdc-tree-node-panel__hint">{{text('renomear dica')}}</p>
            </template>
            <template #actions="modal">
                <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
                <button type="button" class="button button--primary" @click="run(modal, () => curation.rename(newName))">{{text('salvar')}}</button>
            </template>
            <template #button="modal">
                <button type="button" class="button button--sm button--primary-outline" :disabled="busy" @click="modal.open()">{{text('renomear')}}</button>
            </template>
        </mc-modal>

        <mc-modal v-if="childLevel" :title="format(isRoot ? 'adicionar segmento' : 'adicionar abaixo de', node.name)" @open="childName = ''">
            <template #default>
                <div class="field">
                    <label for="mdc-tree-novo-filho">{{format('nome da', childLevel)}}</label>
                    <input id="mdc-tree-novo-filho" v-model="childName" type="text" maxlength="255">
                </div>
                <p class="mdc-tree-node-panel__hint">{{text('adicionar dica')}}</p>
            </template>
            <template #actions="modal">
                <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
                <button type="button" class="button button--primary" @click="run(modal, () => curation.add(childName))">{{text('adicionar')}}</button>
            </template>
            <template #button="modal">
                <button type="button" class="button button--sm button--primary-outline" :disabled="busy" @click="modal.open()">{{isRoot ? text('adicionar segmento botao') : text('adicionar abaixo')}}</button>
            </template>
        </mc-modal>

        <mc-modal v-if="!isRoot && node.enabled" :title="format('desativar titulo', node.name)">
            <template #default>
                <p>{{branch.all ? format('desativar ramo', branch.active) : text('desativar sozinho')}}</p>
                <ul class="mdc-tree-node-panel__effects">
                    <li>{{text('efeito formulario')}}</li>
                    <li>{{text('efeito enviados')}}</li>
                    <li>{{node.drafts ? format('efeito rascunhos', node.drafts) : text('efeito sem rascunhos')}}</li>
                    <li>{{text('efeito volta')}}</li>
                </ul>
            </template>
            <template #actions="modal">
                <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
                <button type="button" class="button button--primary" @click="run(modal, () => curation.disable())">{{text('desativar')}}</button>
            </template>
            <template #button="modal">
                <button type="button" class="button button--sm button--text button--text-danger" :disabled="busy" @click="modal.open()">{{text('desativar botao')}}</button>
            </template>
        </mc-modal>

        <button v-if="!isRoot && !node.enabled" type="button" class="button button--sm button--primary-outline" :disabled="busy || parentDisabled" @click="curation.enable()">{{text('reativar')}}</button>
        <p v-if="!isRoot && !node.enabled" class="mdc-tree-node-panel__hint">{{parentDisabled ? format('reativar pai', parent.name) : text('reativar dica')}}</p>
    </div>

    <div v-if="!isRoot && node.enabled" class="mdc-tree-node-panel__order">
        <button type="button" class="button button--sm button--primary-outline" :disabled="busy || position <= 0" @click="curation.reorder(-1)">{{text('subir')}}</button>
        <button type="button" class="button button--sm button--primary-outline" :disabled="busy || position >= siblings.length - 1" @click="curation.reorder(1)">{{text('descer')}}</button>
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
