<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<div class="mdc-festivity-classification">
    <div class="field" :class="{error: errors(keys.segment).length}">
        <label class="field__title" for="mdc-festivity-segment">
            {{text('segmento')}}
            <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
        </label>
        <select id="mdc-festivity-segment" :value="segment" @change="changeSegment($event.target.value)">
            <option value="">{{text('escolha')}}</option>
            <option v-for="item in classification" :key="item.segmento" :value="item.segmento">{{item.segmento}}</option>
        </select>
        <small v-for="error in errors(keys.segment)" class="field__error">{{error}}</small>
    </div>

    <fieldset v-if="segment" class="field mdc-festivity-classification__typologies" :class="{error: errors(keys.typologies).length}">
        <legend class="field__title">
            {{text('tipologias')}}
            <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
        </legend>
        <small class="field__description">{{text('ate duas')}}</small>

        <label v-for="typology in typologies" :key="typology" class="mdc-festivity-classification__option">
            <input type="checkbox" :checked="selected.includes(typology)" :disabled="isFull && !selected.includes(typology)" @change="toggle(typology)">
            {{typology}}
        </label>
        <label class="mdc-festivity-classification__option">
            <input type="checkbox" :checked="hasOther" :disabled="isFull && !hasOther" @change="toggleOther">
            {{text('outro')}}
        </label>
        <input v-if="hasOther" type="text" class="mdc-festivity-classification__other" :value="entity[keys.other]" :aria-label="text('qual outro')" :placeholder="text('qual outro')" @input="entity[keys.other] = $event.target.value">

        <small v-for="error in errors(keys.typologies)" class="field__error">{{error}}</small>
    </fieldset>
</div>
