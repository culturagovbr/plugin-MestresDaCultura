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
<fieldset class="field mdc-festivity-checklist mdc-festivity-choices" :class="{error: errors.length}">
    <legend class="field__title">
        {{title}}
        <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
    </legend>
    <small v-if="description" class="field__description">{{description}}</small>

    <div class="mdc-festivity-choices__selected" aria-live="polite">
        <span class="mdc-festivity-choices__selected-title">{{text('selecionados')}}</span>
        <p v-if="!tags.length" class="mdc-festivity-choices__empty">{{text('nenhum')}}</p>

        <!-- Marcação do mc-tag-list. -->
        <div v-else class="mc-tag-list">
            <ul class="mc-tag-list__tagList">
                <li v-for="tag in tags" :key="tag.key" class="mc-tag-list__tag mc-tag-list__tag--editable">
                    <span>{{tag.label}}</span>
                    <mc-icon name="delete" @click="tag.remove()" is-link></mc-icon>
                </li>
            </ul>
        </div>
    </div>

    <template v-for="(group, index) in groups" :key="group.label || index">
        <!-- O grupo com nome é um acordeão numerado, como os segmentos da Atuação, aberto se já tem item marcado. -->
        <mc-accordion v-if="group.label" :open="countIn(group) > 0" class="mdc-festivity-choices__segment">
            <template #title>
                <span class="mdc-festivity-choices__segment-title">
                    {{index + 1}}. {{group.label}}
                    <span v-if="countIn(group)" class="mdc-festivity-choices__count">{{countIn(group)}}</span>
                </span>
            </template>
            <template #content>
                <div class="mdc-festivity-choices__list">
                    <label v-for="item in group.items" :key="item.value" class="check-row" :class="{ selected: checked.includes(item.value) }">
                        <input type="checkbox" :checked="checked.includes(item.value)" @change="toggle(item.value)">
                        {{item.label}}
                    </label>
                </div>
            </template>
        </mc-accordion>

        <div v-else class="mdc-festivity-choices__list">
            <label v-for="item in group.items" :key="item.value" class="check-row" :class="{ selected: checked.includes(item.value) }">
                <input type="checkbox" :checked="checked.includes(item.value)" @change="toggle(item.value)">
                {{item.label}}
            </label>
        </div>
    </template>

    <div class="field">
        <label :for="otherId">{{text('outro')}}</label>
        <input type="text" :id="otherId" :value="entity[otherProp]" :placeholder="text('outro ajuda')" @input="entity[otherProp] = $event.target.value">
    </div>

    <small v-for="error in errors" class="field__error">{{error}}</small>
</fieldset>
