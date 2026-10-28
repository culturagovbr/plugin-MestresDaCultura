<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    entity-location
    mc-modal
');
?>
<!-- O nome e o endereço do local, pelo CEP. -->
<mc-modal ref="modal" :title="place && !place.id ? text('titulo novo') : text('titulo')" classes="mdc-place-editor" @close="place = null">
    <template #default>
        <div v-if="place" class="mdc-place-editor__form">
            <entity-field :entity="place" prop="name" :label="text('nome')"></entity-field>
            <entity-location :entity="place" editable hide-label></entity-location>
        </div>
    </template>

    <template #actions="modal">
        <button type="button" class="button button--text" @click="modal.close()">{{text('cancelar')}}</button>
        <button type="button" class="button button--primary" :disabled="saving" @click="save(modal)">{{text('salvar')}}</button>
    </template>
</mc-modal>
