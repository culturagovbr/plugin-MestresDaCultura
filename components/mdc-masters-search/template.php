<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-entities
    mdc-master-card
    search-filter
');
?>
<div class="grid-12 search-list mdc-masters-search">
    <div class="col-4 search-list__filter">
        <div class="search-list__filter--filter">
        <search-filter :pseudo-query="pseudoQuery" position="list">
            <div class="mdc-masters-search__fields">
                <label class="field">
                    <span class="field__title"><?php i::_e('Estado (UF)') ?></span>
                    <select v-model="uf">
                        <option value=""><?php i::_e('Todos') ?></option>
                        <option v-for="option in ufs" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field__title"><?php i::_e('Cidade') ?></span>
                    <input v-model.trim="city" type="text">
                </label>
                <label class="field">
                    <span class="field__title"><?php i::_e('Atuação: segmento') ?></span>
                    <select v-model="segment">
                        <option value=""><?php i::_e('Todos') ?></option>
                        <option v-for="node in segments" :key="node.id" :value="node.id">{{ node.name }}</option>
                    </select>
                </label>
                <label v-if="typologies.length" class="field">
                    <span class="field__title"><?php i::_e('Atuação: tipologia') ?></span>
                    <select v-model="typology">
                        <option value=""><?php i::_e('Todas') ?></option>
                        <option v-for="node in typologies" :key="node.id" :value="node.id">{{ node.name }}</option>
                    </select>
                </label>
                <button type="button" class="button button--text mdc-masters-search__clear" @click="clear()"><?php i::_e('Limpar filtros') ?></button>
            </div>
        </search-filter>
        </div>
    </div>

    <div class="col-8">
        <mc-entities type="agent" :select="select" :query="query" order="name ASC" :limit="12" watch-query>
            <template #header="{entities}">
                <p v-if="!entities.loading" class="mdc-masters-search__count" role="status">
                    {{ entities.metadata?.count ?? 0 }} <?php i::_e('mestras e mestres encontrados') ?>
                </p>
            </template>
            <template #default="{entities}">
                <div class="mestres-grid">
                    <mdc-master-card v-for="entity in entities" :key="entity.__objectId" :master="card(entity)"></mdc-master-card>
                </div>
            </template>
            <template #empty>
                <p class="mdc-masters-search__empty"><?php i::_e('Nenhuma mestra ou mestre encontrado com esses filtros.') ?></p>
            </template>
        </mc-entities>
    </div>
</div>
