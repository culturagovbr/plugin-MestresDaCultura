<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-accordion
    mc-alert
    mc-icon
    mc-loading
    mdc-acting-list
');
?>
<div class="mdc-acting-field">
    <p class="mdc-acting-field__hint">{{text('como')}}</p>

    <mc-loading :condition="loading">{{text('carregando')}}</mc-loading>
    <mc-alert v-if="failed" type="danger">{{text('falha')}}</mc-alert>
    <mc-alert v-if="removed" type="warning">{{text('removidos')}}</mc-alert>

    <template v-if="tree">
        <div class="mdc-acting-field__selected" aria-live="polite">
            <span class="mdc-acting-field__selected-title">{{text('selecionados')}}</span>
            <p v-if="!tags.length" class="mdc-acting-field__empty">{{text('nenhum')}}</p>

            <!-- Marcação do mc-tag-list, com a cor própria do segmento. -->
            <div v-else class="mc-tag-list">
                <ul class="mc-tag-list__tagList">
                    <li v-for="tag in tags" :key="tag.key" class="mc-tag-list__tag" :class="{ 'mc-tag-list__tag--editable': !disabled, 'mdc-acting-field__tag--segment': tag.segment }">
                        <span>{{tag.label}}</span>
                        <mc-icon v-if="!disabled" name="delete" @click="tag.remove()" is-link></mc-icon>
                    </li>
                </ul>
            </div>
        </div>

        <!-- A chave muda com a marcação: o acordeão abre ou fecha junto. -->
        <mc-accordion v-for="(segment, index) in tree" :key="segment.id + (isChecked(segment) ? '-marcado' : '')" :open="isChecked(segment)" class="mdc-acting-field__segment">
            <template #title>
                <label class="mdc-acting-field__segment-title" @click.stop>
                    <input type="checkbox" :checked="isChecked(segment)" :disabled="disabled" @change="toggle(segment, $event.target.checked)">
                    {{index + 1}}. {{segment.name}}
                </label>
            </template>
            <template #content>
                <mdc-acting-list v-if="isChecked(segment)" :parent="segment"></mdc-acting-list>
                <p v-else class="mdc-acting-field__empty">{{format('fechado', segment.children.length)}}</p>
            </template>
        </mc-accordion>

        <div class="field">
            <label :for="'mdc-acting-general-' + uid">{{text('outro geral')}}</label>
            <input type="text" :id="'mdc-acting-general-' + uid" :value="otherOf(null)" :placeholder="text('outro geral ajuda')" :maxlength="otherMaxLength" :disabled="disabled" @change="setOther(null, $event.target.value)">
        </div>
    </template>
</div>
