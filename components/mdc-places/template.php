<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-confirm-button
    mc-icon
    mc-status
    mdc-place-editor
');
?>
<div class="mdc-places">
    <div class="mdc-places__new">
        <button type="button" class="button button--primary button--icon" @click="$refs.editor.create()">
            <mc-icon name="add"></mc-icon> {{text('cadastrar')}}
        </button>
    </div>

    <p v-if="!places.length" class="mdc-places__empty">{{text('vazio')}}</p>

    <article v-for="place in places" :key="place.id" class="mdc-places__place">
        <header class="mdc-places__header">
            <h2 class="mdc-places__name">{{place.name}}</h2>
            <mc-status :class="{ 'mdc-places__status--published': place.status > 0 }" :status-name="place.status > 0 ? text('publicado') : text('rascunho')"></mc-status>
        </header>

        <p class="mdc-places__address">{{addressOf(place)}}</p>

        <div class="mdc-places__festivities">
            <span class="mdc-places__label">{{text('festejos')}}</span>
            <ul v-if="place.festivities.length">
                <li v-for="festivity in place.festivities" :key="festivity.id">
                    <a :href="editUrl + festivity.id + '/'">{{festivity.name}}</a>
                </li>
            </ul>
            <p v-else>{{text('sem festejo')}}</p>
        </div>

        <div class="mdc-places__actions">
            <button type="button" class="button button--sm button--primary-outline" @click="$refs.editor.open(place.id)">
                <mc-icon name="edit"></mc-icon> {{text('editar')}}
            </button>

            <mc-confirm-button v-if="!place.festivities.length" @confirm="remove(place)">
                <template #button="modal">
                    <button type="button" class="button button--sm button--text button--icon" @click="modal.open()">
                        <mc-icon name="trash"></mc-icon> {{text('excluir')}}
                    </button>
                </template>
                <template #message>{{format('excluir pergunta', place.name)}}</template>
            </mc-confirm-button>
            <small v-else class="mdc-places__locked">{{text('em uso')}}</small>
        </div>

    </article>

    <mdc-place-editor ref="editor" @saved="updated"></mdc-place-editor>
</div>
