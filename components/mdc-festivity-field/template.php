<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<div class="mdc-festivity-field">
    <div v-if="chosen" class="mdc-festivity-field__chosen">
        <span>
            <a ref="chosen" :href="chosen.singleUrl" target="_blank" rel="noopener">{{ chosen.name }}</a>
            <small v-if="placeOf(chosen)">{{ placeOf(chosen) }}</small>
        </span>
        <button v-if="!disabled" type="button" class="button button--text button--sm" @click="clear">{{ text('trocar') }}</button>
    </div>

    <template v-else>
        <p v-if="legacy" class="mdc-festivity-field__legacy">{{ text('resposta anterior') }} <strong>{{ legacy }}</strong></p>

        <template v-if="!disabled">
            <!-- O nome vem do `<label>` do entity-field (field-id); a dica fica no placeholder. -->
            <input ref="search" type="search" :id="fieldId || undefined" v-model="query" :placeholder="text('buscar')" autocomplete="off" @input="search">
            <ul v-if="results.length" class="mdc-festivity-field__results" :aria-label="text('resultados')">
                <li v-for="festivity in results" :key="festivity.id">
                    <button type="button" class="mdc-festivity-field__result" @click="choose(festivity)">
                        {{ festivity.name }}<small v-if="placeOf(festivity)"> · {{ placeOf(festivity) }}</small>
                    </button>
                </li>
            </ul>
            <p v-else-if="searched" class="mdc-festivity-field__empty" role="status">
                {{ text('nenhum') }} <a :href="formUrl" target="_blank" rel="noopener">{{ text('cadastre') }}</a>
            </p>
        </template>
    </template>

    <p class="sr-only" role="status">{{ announcement }}</p>
</div>
