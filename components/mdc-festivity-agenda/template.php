<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    entity-location
    mc-alert
    mc-confirm-button
    mc-icon
    mc-loading
    mdc-place-editor
');
?>
<div class="mdc-festivity-agenda" :class="{error: errors.length}">
    <div class="field">
        <span class="field__title">
            {{text('titulo')}}
            <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
        </span>
        <small class="field__description">{{text('dica')}}</small>
    </div>

    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha leitura')}}</mc-alert>

    <ul v-if="occurrences.length" class="mdc-festivity-agenda__list">
        <li v-for="occurrence in occurrences" :key="occurrence.id" class="mdc-festivity-agenda__item">
            <div class="mdc-festivity-agenda__when">
                <strong>{{occurrence.rule?.description}}</strong>
                <span v-if="!isRemoved(occurrence)">{{occurrence.space.name}} · {{placeOf(occurrence.space)}}</span>
                <!-- Local na lixeira: a etapa cobra a troca. -->
                <span v-else class="mdc-festivity-agenda__removed" role="alert">{{text('local removido')}}</span>
            </div>
            <button v-if="canEditPlace(occurrence)" type="button" class="button button--text button--sm button--icon" @click="$refs.editor.open(occurrence.space.id)">
                <mc-icon name="edit"></mc-icon> {{text('editar local')}}
            </button>
            <mc-confirm-button @confirm="remove(occurrence)">
                <template #button="modal">
                    <button type="button" class="button button--text button--sm button--icon" @click="modal.open()">
                        <mc-icon name="trash"></mc-icon> {{text('remover')}}
                    </button>
                </template>
                <template #message>{{text('remover pergunta')}}</template>
            </mc-confirm-button>
        </li>
    </ul>
    <!-- "Vazia" só com a agenda lida. -->
    <p v-else-if="!loading && !failed" class="mdc-festivity-agenda__empty">{{text('vazia')}}</p>

    <mdc-place-editor ref="editor" @saved="refresh"></mdc-place-editor>

    <div v-if="adding" class="mdc-festivity-agenda__new">
        <div class="mdc-festivity-agenda__dates">
            <div class="field">
                <label class="field__title" for="mdc-festivity-starts-on">{{text('inicio')}}</label>
                <input id="mdc-festivity-starts-on" type="date" v-model="startsOn">
            </div>
            <div class="field">
                <label class="field__title" for="mdc-festivity-until">{{text('fim')}}</label>
                <input id="mdc-festivity-until" type="date" v-model="until" :min="startsOn">
            </div>
            <div class="field">
                <label class="field__title" for="mdc-festivity-starts-at">{{text('horario inicio')}}</label>
                <input id="mdc-festivity-starts-at" type="time" v-model="startsAt">
            </div>
            <div class="field">
                <label class="field__title" for="mdc-festivity-ends-at">{{text('horario fim')}}</label>
                <input id="mdc-festivity-ends-at" type="time" v-model="endsAt">
            </div>
        </div>

        <div class="field">
            <label class="field__title" for="mdc-festivity-place">{{text('local')}}</label>
            <select id="mdc-festivity-place" v-model="placeId" @change="choosePlace">
                <option value="">{{text('escolha o local')}}</option>
                <option v-for="place in places" :key="place.id" :value="place.id">{{place.name}} · {{placeOf(place)}}</option>
                <option value="new">{{text('novo local')}}</option>
            </select>
        </div>

        <div v-if="placeId === 'new' && newPlace" class="mdc-festivity-agenda__place">
            <entity-field :entity="newPlace" prop="name" :label="text('nome do local')" :field-description="text('nome do local dica')"></entity-field>
            <entity-location :entity="newPlace" editable hide-label></entity-location>
        </div>

        <p v-if="error" class="field__error" role="alert">{{error}}</p>

        <div class="mdc-festivity-agenda__actions">
            <button type="button" class="button button--primary-outline button--sm" :disabled="saving" @click="cancel">{{text('cancelar')}}</button>
            <button type="button" class="button button--primary button--sm" :disabled="saving" @click="add">{{text('adicionar')}}</button>
        </div>
    </div>
    <button v-else-if="!loading && !failed" type="button" class="button button--primary-outline button--icon mdc-festivity-agenda__start" @click="start">
        <mc-icon name="add"></mc-icon> {{text('novo periodo')}}
    </button>

    <small v-for="message in errors" class="field__error">{{message}}</small>
</div>
