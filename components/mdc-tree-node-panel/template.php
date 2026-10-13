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

    <div v-if="duplicates.length && !reference" class="mdc-tree-node-panel__equivalence">
        <p><strong>{{text('possivel duplicata')}}</strong></p>
        <ul>
            <li v-for="item in duplicates" :key="item.node.id"><button type="button" class="mdc-tree-node-panel__link" @click="$emit('select', item.node.id)">{{item.path.join(' › ')}}</button></li>
        </ul>
        <p class="mdc-tree-node-panel__hint">{{text('duplicata dica')}}</p>
        <button type="button" class="button button--sm button--text" :disabled="busy" @click="curation.markLegitimate()">{{text('repeticao legitima')}}</button>
    </div>

    <div v-if="node.legitimate" class="mdc-tree-node-panel__equivalence">
        <p>{{text('legitima marcada')}}</p>
        <button type="button" class="button button--sm button--text" :disabled="busy" @click="curation.unmarkLegitimate()">{{text('voltar a alertar')}}</button>
    </div>

    <div v-if="reference" class="mdc-tree-node-panel__equivalence">
        <p>{{text('equivale a')}} <button type="button" class="mdc-tree-node-panel__link" @click="$emit('select', reference.node.id)">{{reference.path.join(' › ')}}</button></p>
        <p class="mdc-tree-node-panel__hint">{{text('equivalencia efeito')}}</p>
        <button type="button" class="button button--sm button--text" :disabled="busy" @click="curation.undoEquivalence()">{{text('desfazer equivalencia')}}</button>
    </div>

    <div v-if="equivalents.length" class="mdc-tree-node-panel__equivalence">
        <p>{{text('equivalem a este')}}</p>
        <ul>
            <li v-for="item in equivalents" :key="item.node.id"><button type="button" class="mdc-tree-node-panel__link" @click="$emit('select', item.node.id)">{{item.path.join(' › ')}}</button></li>
        </ul>
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

        <mc-modal v-if="!isRoot && node.enabled" :title="format('mover titulo', node.name)" @open="startMove">
            <template #default>
                <div class="field">
                    <label for="mdc-tree-mover-busca">{{text('para onde')}}</label>
                    <input id="mdc-tree-mover-busca" v-model="moveQuery" type="search" autocomplete="off" :placeholder="text('para onde exemplo')">
                </div>
                <ul class="mdc-tree-node-panel__picker">
                    <li v-for="target in moveFound.slice(0, moveLimit)" :key="target.id">
                        <button type="button" class="mdc-tree-node-panel__pick" :aria-pressed="target.id === moveTarget" @click="moveTarget = target.id">{{target.place}}</button>
                    </li>
                    <li v-if="!moveFound.length" class="mdc-tree-node-panel__empty">{{text('nenhum destino')}}</li>
                    <li v-if="moveFound.length > moveLimit" class="mdc-tree-node-panel__empty">{{format('mais destinos', moveFound.length - moveLimit)}}</li>
                </ul>
                <p class="mdc-tree-node-panel__hint">{{branch.all ? format('mover ramo', branch.all) : text('mover sozinho')}} {{text('mover regra')}}</p>
            </template>
            <template #actions="modal">
                <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
                <button type="button" class="button button--primary" :disabled="moveTarget === null" @click="run(modal, () => curation.move(moveTarget))">{{text('mover')}}</button>
            </template>
            <template #button="modal">
                <button type="button" class="button button--sm button--primary-outline" :disabled="busy || !moveTargets.length" @click="modal.open()">{{text('mover botao')}}</button>
            </template>
        </mc-modal>

        <mc-modal v-if="equivalenceTargets.length" :title="format('equivalente titulo', node.name)" @open="startEquivalence">
            <template #default>
                <div class="field">
                    <label for="mdc-tree-equivalente-busca">{{text('equivale a qual')}}</label>
                    <input id="mdc-tree-equivalente-busca" v-model="equivalenceQuery" type="search" autocomplete="off">
                </div>
                <ul class="mdc-tree-node-panel__picker">
                    <li v-for="target in equivalenceFound.slice(0, moveLimit)" :key="target.id">
                        <button type="button" class="mdc-tree-node-panel__pick" :aria-pressed="target.id === equivalenceTarget" @click="equivalenceTarget = target.id">{{target.place}}</button>
                    </li>
                    <li v-if="!equivalenceFound.length" class="mdc-tree-node-panel__empty">{{text('nenhum item')}}</li>
                </ul>
                <p class="mdc-tree-node-panel__hint">{{text('equivalencia efeito')}}</p>
            </template>
            <template #actions="modal">
                <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
                <button type="button" class="button button--primary" :disabled="equivalenceTarget === null" @click="run(modal, () => curation.markEquivalent(equivalenceTarget))">{{text('marcar')}}</button>
            </template>
            <template #button="modal">
                <button type="button" class="button button--sm button--primary-outline" :disabled="busy" @click="modal.open()">{{text('equivalente botao')}}</button>
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

    <details v-if="!isRoot" :key="node.id" class="mdc-tree-node-panel__history" @toggle="loadHistory">
        <summary>{{text('historico')}}</summary>
        <p v-if="history === null" class="mdc-tree-node-panel__hint">{{text('carregando historico')}}</p>
        <p v-else-if="!history.length" class="mdc-tree-node-panel__hint">{{text('sem historico')}}</p>
        <ol v-else>
            <li v-for="(entry, index) in history" :key="index">
                <small>{{when(entry.at)}} · {{entry.who}}</small>
                <span>{{entry.changes.join('; ')}}</span>
            </li>
        </ol>
    </details>

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
