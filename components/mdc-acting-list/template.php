<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

// Importado por `mdc-acting-field`; a recursão usa o registro global do componente.
?>
<div class="mdc-acting-list">
    <template v-for="node in parent.children" :key="node.id">
        <label class="check-row" :class="{ selected: acting.isChecked(node) }">
            <input type="checkbox" :checked="acting.isChecked(node)" :disabled="acting.disabled" @change="acting.toggle(node, $event.target.checked)">
            {{node.name}}
        </label>

        <div v-if="acting.isChecked(node) && node.children.length" class="subtipologia-box">
            <span class="subtipologia-box__title">{{acting.childrenHeading(node)}}</span>
            <mdc-acting-list :parent="node"></mdc-acting-list>
        </div>
    </template>

    <div class="field">
        <label :for="otherId">{{acting.text('outro')}}</label>
        <input type="text" :id="otherId" :value="acting.otherOf(parent.id)" :maxlength="acting.otherMaxLength" :disabled="acting.disabled" @change="acting.setOther(parent.id, $event.target.value)">
    </div>
</div>
