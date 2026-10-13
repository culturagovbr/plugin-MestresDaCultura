<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<div class="mdc-tree-search" @focusout="leave" @keydown.esc="open = false">
    <div class="field">
        <label :for="uid" class="mdc-tree-search__label">{{text('buscar')}}</label>
        <input :id="uid" v-model="query" type="search" autocomplete="off" :placeholder="text('exemplo')" @input="open = true" @focus="open = true">
    </div>

    <div v-if="open && query.trim().length >= 2" class="mdc-tree-search__results" role="listbox" :aria-label="text('resultados')">
        <p v-if="!found.length" class="mdc-tree-search__empty">{{text('nada')}}</p>
        <button v-for="item in found.slice(0, limit)" :key="item.id" type="button" class="mdc-tree-search__result" role="option" @mousedown.prevent @click="choose(item)">
            <span :class="{ 'mdc-tree-search__off': !item.enabled }">{{item.name}}</span>
            <small v-if="!item.enabled">{{text('desativado')}}</small>
            <small>{{item.place || text('segmento')}}</small>
        </button>
        <p v-if="found.length > limit" class="mdc-tree-search__empty">{{format('mais', found.length - limit)}}</p>
    </div>
</div>
