<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-accordion
    mc-icon
');
?>
<fieldset class="field mdc-festivity-classification mdc-festivity-choices" :class="{error: messages.length}">
    <legend class="field__title">
        {{text('classificacao')}}
        <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
    </legend>
    <small class="field__description">{{text('como')}}</small>

    <div class="mdc-festivity-choices__selected" aria-live="polite">
        <span class="mdc-festivity-choices__selected-title">{{text('selecionados')}}</span>
        <p v-if="!tags.length" class="mdc-festivity-choices__empty">{{text('nenhum')}}</p>

        <!-- Marcação do mc-tag-list, com a cor própria do segmento. -->
        <div v-else class="mc-tag-list">
            <ul class="mc-tag-list__tagList">
                <li v-for="tag in tags" :key="tag.key" class="mc-tag-list__tag mc-tag-list__tag--editable" :class="{ 'mdc-festivity-choices__tag--segment': tag.segment }">
                    <span>{{tag.label}}</span>
                    <mc-icon name="delete" @click="tag.remove()" is-link></mc-icon>
                </li>
            </ul>
        </div>
    </div>

    <!-- Um segmento só, pelo rádio; a chave muda com a escolha, e o acordeão abre junto. -->
    <mc-accordion v-for="(item, index) in classification" :key="item.segmento + (segment === item.segmento ? '-marcado' : '')" :open="segment === item.segmento" class="mdc-festivity-choices__segment">
        <template #title>
            <label class="mdc-festivity-choices__segment-title" @click.stop>
                <input type="radio" name="mdc-festivity-segment" :ref="'segment-' + index" :checked="segment === item.segmento" @change="chooseSegment(item.segmento, index)">
                {{index + 1}}. {{item.segmento}}
            </label>
        </template>
        <template #content>
            <div v-if="segment === item.segmento" class="mdc-festivity-choices__list">
                <p class="mdc-festivity-choices__hint">{{text('ate duas')}}</p>
                <label v-for="typology in item.tipologias" :key="typology" class="check-row" :class="{ selected: selected.includes(typology) }">
                    <input type="checkbox" :checked="selected.includes(typology)" :disabled="isFull && !selected.includes(typology)" @change="toggle(typology)">
                    {{typology}}
                </label>
                <div class="field">
                    <label for="mdc-festivity-typology-other">{{text('outro')}}</label>
                    <input type="text" id="mdc-festivity-typology-other" :value="entity[keys.other]" :disabled="isFull && !entity[keys.other]" :placeholder="text('outro ajuda')" @input="entity[keys.other] = $event.target.value">
                </div>
            </div>
            <p v-else class="mdc-festivity-choices__empty">{{format('fechado', item.tipologias.length)}}</p>
        </template>
    </mc-accordion>

    <small v-for="error in messages" class="field__error">{{error}}</small>
</fieldset>
