<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-alert
    mc-loading
    mc-modal
');
?>
<div class="mdc-tree-others">
    <p class="mdc-tree-others__intro">{{text('intro')}}</p>

    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha')}}</mc-alert>

    <template v-if="!loading && !failed">
        <div v-if="entries.length" class="field mdc-tree-others__search">
            <label for="mdc-others-busca">{{text('buscar')}}</label>
            <input id="mdc-others-busca" v-model="search" type="search" autocomplete="off" :placeholder="text('buscar exemplo')">
        </div>

        <p v-if="!pending.length" class="mdc-tree-others__empty">{{text('nada pendente')}}</p>
        <p v-else-if="!foundPending.length" class="mdc-tree-others__empty">{{format('nada encontrado', search)}}</p>

        <article v-for="entry in foundPending.slice(0, shownPending)" :key="entry.parent + '|' + entry.key" class="mdc-tree-others__item">
            <div class="mdc-tree-others__body">
                <p class="mdc-tree-others__text">“{{entry.text}}”</p>
                <p class="mdc-tree-others__meta">{{format('escrito em', placeOf(entry))}} · {{format('contagem', entry.sent, entry.drafts)}}</p>
                <div v-if="entry.suggestions.length" class="mdc-tree-others__meta">
                    {{text('parecido com')}}
                    <ul class="mdc-tree-others__suggestions">
                        <li v-for="id in entry.suggestions" :key="id"><button type="button" class="mdc-tree-others__link" @click="curation.goTo(id)">{{pathOf(id)}}</button></li>
                    </ul>
                </div>
            </div>
            <div class="mdc-tree-others__actions">
                <button type="button" class="button button--sm button--primary" :disabled="curation.busy" @click="open('create', entry)">{{text('criar botao')}}</button>
                <button type="button" class="button button--sm button--primary-outline" :disabled="curation.busy" @click="open('associate', entry)">{{text('associar botao')}}</button>
                <button type="button" class="button button--sm button--text" :disabled="curation.busy" @click="open('archive', entry)">{{text('arquivar botao')}}</button>
            </div>
        </article>

        <button v-if="foundPending.length > shownPending" type="button" class="button button--sm button--primary-outline mdc-tree-others__more" @click="showMore('shownPending')">{{format('mostrar mais', foundPending.length - shownPending)}}</button>

        <details v-if="decided.length" class="mdc-tree-others__decided" @toggle="decidedOpen = $event.target.open">
            <summary>{{search ? format('tratados busca', foundDecided.length, decided.length) : format('tratados', decided.length)}}</summary>
            <template v-if="decidedOpen">
                <div class="mdc-tree-others__filter" role="group" :aria-label="text('filtro decisao')">
                    <button v-for="type in ['', 'created', 'associated', 'archived']" :key="type" type="button" class="mdc-tree-others__chip" :aria-pressed="decisionFilter === type" @click="decisionFilter = type">{{format('filtro ' + (type || 'todos'), decisionCounts[type])}}</button>
                </div>
                <p v-if="!filteredDecided.length" class="mdc-tree-others__empty">{{text('nada nos tratados')}}</p>
                <div class="mdc-tree-others__done-list">
                    <article v-for="entry in filteredDecided.slice(0, shownDecided)" :key="entry.parent + '|' + entry.key" class="mdc-tree-others__done">
                        <p class="mdc-tree-others__text">“{{entry.text}}”</p>
                        <p class="mdc-tree-others__meta">{{format('escrito em', placeOf(entry))}}</p>
                        <p class="mdc-tree-others__decision">
                            <span class="mdc-tree-others__badge" :class="'mdc-tree-others__badge--' + entry.decision.type">{{text('decisao ' + entry.decision.type)}}</span>
                            <button v-if="entry.decision.node && curation.index[entry.decision.node]" type="button" class="mdc-tree-others__link" @click="curation.goTo(entry.decision.node)">{{pathOf(entry.decision.node)}}</button>
                            <span v-else-if="entry.decision.type !== 'archived'">{{text('item removido')}}</span>
                            <span v-else>{{entry.decision.note ? format('motivo arquivado', entry.decision.note) : text('sem motivo')}}</span>
                        </p>
                        <p class="mdc-tree-others__meta">{{format('decidido em', when(entry.decision.at))}}</p>
                    </article>
                </div>
                <button v-if="filteredDecided.length > shownDecided" type="button" class="button button--sm button--primary-outline mdc-tree-others__more" @click="showMore('shownDecided')">{{format('mostrar mais', filteredDecided.length - shownDecided)}}</button>
            </template>
        </details>
    </template>

    <mc-modal ref="create" :title="text('criar titulo')">
        <template #default>
            <div class="field">
                <label for="mdc-others-nome">{{text('nome')}}</label>
                <input id="mdc-others-nome" v-model="name" type="text" maxlength="255">
            </div>
            <div class="field">
                <label for="mdc-others-lugar">{{text('onde entra')}}</label>
                <input id="mdc-others-lugar" v-model="query" type="search" autocomplete="off" :placeholder="text('buscar lugar')">
            </div>
            <ul class="mdc-tree-others__picker">
                <li v-for="target in found(places).slice(0, limit)" :key="target.id">
                    <button type="button" class="mdc-tree-others__pick" :aria-pressed="target.id === chosen" @click="chosen = target.id">{{target.place}}</button>
                </li>
            </ul>
            <p class="mdc-tree-others__hint">{{text('criar dica')}}</p>
        </template>
        <template #actions="modal">
            <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
            <button type="button" class="button button--primary" :disabled="chosen === null || !name.trim()" @click="create(modal)">{{text('criar')}}</button>
        </template>
    </mc-modal>

    <mc-modal ref="associate" :title="text('associar titulo')">
        <template #default>
            <div class="field">
                <label for="mdc-others-item">{{text('item existente')}}</label>
                <input id="mdc-others-item" v-model="query" type="search" autocomplete="off">
            </div>
            <ul class="mdc-tree-others__picker">
                <li v-for="target in found(items).slice(0, limit)" :key="target.id">
                    <button type="button" class="mdc-tree-others__pick" :aria-pressed="target.id === chosen" @click="chosen = target.id">{{target.place}}</button>
                </li>
            </ul>
            <p class="mdc-tree-others__hint">{{text('associar dica')}}</p>
        </template>
        <template #actions="modal">
            <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
            <button type="button" class="button button--primary" :disabled="chosen === null" @click="associate(modal)">{{text('associar')}}</button>
        </template>
    </mc-modal>

    <mc-modal ref="archive" :title="text('arquivar titulo')">
        <template #default>
            <div class="field">
                <label for="mdc-others-motivo">{{text('motivo')}}</label>
                <textarea id="mdc-others-motivo" v-model="note" rows="3"></textarea>
            </div>
            <p class="mdc-tree-others__hint">{{text('arquivar dica')}}</p>
        </template>
        <template #actions="modal">
            <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
            <button type="button" class="button button--primary" @click="archive(modal)">{{text('arquivar')}}</button>
        </template>
    </mc-modal>
</div>
