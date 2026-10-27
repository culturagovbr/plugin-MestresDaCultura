<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<fieldset class="field mdc-festivity-checklist" :class="{error: errors.length}">
    <legend class="field__title">
        {{title}}
        <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
    </legend>
    <small v-if="description" class="field__description">{{description}}</small>

    <component :is="collapsible ? 'details' : 'div'" v-for="group in groups" :key="group.label" class="mdc-festivity-checklist__group" :open="collapsible && countIn(group) > 0 ? true : undefined">
        <component :is="collapsible ? 'summary' : 'p'" v-if="group.label" class="mdc-festivity-checklist__group-title">
            {{group.label}}
            <span v-if="countIn(group)" class="mdc-festivity-checklist__count">{{countIn(group)}}</span>
        </component>
        <div class="mdc-festivity-checklist__options">
            <label v-for="item in group.items" :key="item.value" class="mdc-festivity-checklist__option">
                <input type="checkbox" :checked="checked.includes(item.value)" @change="toggle(item.value)">
                {{item.label}}
            </label>
        </div>
    </component>

    <label class="mdc-festivity-checklist__option">
        <input type="checkbox" :checked="hasOther" @change="toggleOther">
        {{text('outro')}}
    </label>
    <input v-if="hasOther" type="text" class="mdc-festivity-checklist__other" :value="entity[otherProp]" :aria-label="text('qual')" :placeholder="text('qual')" @input="entity[otherProp] = $event.target.value">

    <small v-for="error in errors" class="field__error">{{error}}</small>
</fieldset>
