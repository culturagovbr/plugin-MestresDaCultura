<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-alert
    mc-icon
    mc-loading
');
?>
<div class="field mdc-festivity-masters" :class="{error: errors.length}">
    <label class="field__title" for="mdc-festivity-masters-search">{{text('titulo')}}</label>
    <small class="field__description">{{text('dica')}}</small>

    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha')}}</mc-alert>

    <ul v-if="selected.length" class="mdc-festivity-masters__selected">
        <li v-for="master in selected" :key="master.id" class="mdc-festivity-masters__chip">
            <span>{{labelOf(master)}}</span>
            <button type="button" :ref="'remove-' + master.id" class="mdc-festivity-masters__remove" :aria-label="text('remover') + ' ' + labelOf(master)" @click="remove(master.id)">
                <mc-icon name="close"></mc-icon>
            </button>
        </li>
    </ul>

    <!-- Só depois de ler os vínculos gravados. -->
    <input v-if="!loading && !failed" ref="search" id="mdc-festivity-masters-search" type="search" v-model="query" :placeholder="text('buscar')" autocomplete="off" @input="search">
    <ul v-if="results.length" class="mdc-festivity-masters__results" :aria-label="text('resultados')">
        <li v-for="master in results" :key="master.id">
            <button type="button" class="mdc-festivity-masters__result" @click="add(master)">{{labelOf(master)}}</button>
        </li>
    </ul>
    <p v-else-if="searched" class="mdc-festivity-masters__empty" role="status">{{text('nenhum')}}</p>

    <small v-for="error in errors" class="field__error">{{error}}</small>
    <p class="sr-only" role="status">{{announcement}}</p>
</div>
