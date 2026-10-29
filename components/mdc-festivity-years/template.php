<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<fieldset class="field mdc-festivity-years" :class="{error: errors.length}">
    <legend class="field__title">
        {{text('anos')}}
        <span v-if="required" class="required">*{{text('obrigatorio')}}</span>
    </legend>
    <small class="field__description">{{text('como')}}</small>

    <div class="mdc-festivity-years__options">
        <button v-for="year in years" :key="year" type="button" class="mdc-festivity-years__year" :class="{ 'mdc-festivity-years__year--selected': selected.includes(year) }" :aria-pressed="selected.includes(year) ? 'true' : 'false'" @click="toggle(year)">
            {{year}}
        </button>
        <button v-if="hasEarlier" type="button" class="button button--text button--sm" @click="shown += 15">{{text('anteriores')}}</button>
    </div>

    <small v-for="error in errors" class="field__error">{{error}}</small>
</fieldset>
