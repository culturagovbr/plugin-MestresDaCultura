<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-entities
    mdc-festivity-card
    search-filter
');
?>
<div class="grid-12 search-list mdc-festivities-search">
    <div class="col-4 search-list__filter">
        <div class="search-list__filter--filter">
        <search-filter :pseudo-query="pseudoQuery" position="list">
            <div class="mdc-festivities-search__fields">
                <label class="field">
                    <span class="field__title"><?php i::_e('Acontece em') ?></span>
                    <select v-model="month">
                        <option value=""><?php i::_e('Qualquer mês') ?></option>
                        <option v-for="(name, index) in months" :key="index" :value="index + 1">{{ name }}</option>
                    </select>
                </label>
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
                    <span class="field__title"><?php i::_e('Segmento') ?></span>
                    <select v-model="segment">
                        <option value=""><?php i::_e('Todos') ?></option>
                        <option v-for="item in classification" :key="item.segmento" :value="item.segmento">{{ item.segmento }}</option>
                    </select>
                </label>
                <label v-if="typologies.length" class="field">
                    <span class="field__title"><?php i::_e('Tipologia') ?></span>
                    <select v-model="typology">
                        <option value=""><?php i::_e('Todas') ?></option>
                        <option v-for="item in typologies" :key="item" :value="item">{{ item }}</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field__title"><?php i::_e('Porte') ?></span>
                    <select v-model="size">
                        <option value=""><?php i::_e('Todos') ?></option>
                        <option v-for="(label, value) in sizes" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>
                <label class="mdc-festivities-search__check">
                    <input v-model="withMasters" type="checkbox"> <?php i::_e('Com Mestras e Mestres da Rede') ?>
                </label>
                <label class="mdc-festivities-search__check">
                    <input v-model="accessible" type="checkbox"> <?php i::_e('Com acessibilidade') ?>
                </label>
                <button type="button" class="button button--text mdc-festivities-search__clear" @click="clear()"><?php i::_e('Limpar filtros') ?></button>
            </div>
        </search-filter>
        </div>
    </div>

    <div class="col-8">
        <div class="mdc-festivities-search__top">
            <p class="mdc-festivities-search__intro"><?php i::_e('Os festejos cadastrados por quem os realiza, com a agenda de cada ano.') ?></p>
            <a class="button button--primary" :href="formUrl"><?php i::_e('Cadastrar festejo') ?></a>
        </div>
        <mc-entities type="event" :select="select" :query="query" order="name ASC" :limit="12" watch-query>
            <template #header="{entities}">
                <p v-if="!entities.loading" class="mdc-festivities-search__count" role="status">
                    {{ entities.metadata?.count ?? 0 }} <?php i::_e('festejos encontrados') ?>
                </p>
            </template>
            <template #default="{entities}">
                <div class="mdc-festivities-search__grid">
                    <mdc-festivity-card v-for="entity in entities" :key="entity.__objectId" :festivity="card(entity)"></mdc-festivity-card>
                </div>
            </template>
            <template #empty>
                <p class="mdc-festivities-search__empty"><?php i::_e('Nenhum festejo encontrado com esses filtros.') ?></p>
            </template>
        </mc-entities>
    </div>
</div>
