<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    mdc-age
');
?>
<div v-if="!canEdit" class="mdc-festivity-person">
    <p class="mdc-festivity-person__privacy">{{text('so a pessoa')}}</p>
</div>
<div v-else-if="agent" class="mdc-festivity-person">
    <template v-if="part === 'contact'">
        <entity-field :entity="agent" prop="nomeCompleto" :label="text('responsavel')" :required="true"></entity-field>
        <div class="mdc-festivity-person__birth">
            <entity-field :entity="agent" prop="dataDeNascimento" :label="text('nascimento')"></entity-field>
            <mdc-age :value="agent.dataDeNascimento"></mdc-age>
        </div>

        <entity-field :entity="agent" prop="emailPrivado" :label="text('email')"></entity-field>
        <label v-if="isOwn" class="mdc-festivity-person__consent">
            <input type="checkbox" :checked="publicEmail" @change="setPublic('email', $event.target.checked)">
            {{text('email publico')}}
        </label>
        <entity-field v-if="publicEmail" :entity="agent" prop="emailPublico" :label="text('email publico campo')"></entity-field>

        <entity-field :entity="agent" prop="telefone1" :label="text('telefone')"></entity-field>
        <label v-if="isOwn" class="mdc-festivity-person__consent">
            <input type="checkbox" :checked="publicPhone" @change="setPublic('phone', $event.target.checked)">
            {{text('telefone publico')}}
        </label>
        <entity-field v-if="publicPhone" :entity="agent" prop="telefonePublico" :label="text('telefone publico campo')"></entity-field>
    </template>

    <template v-else>
        <entity-field :entity="agent" prop="genero" :label="text('genero')"></entity-field>
        <entity-field :entity="agent" prop="orientacaoSexual" :label="text('orientacao')"></entity-field>
        <entity-field :entity="agent" prop="raca" :label="text('raca')"></entity-field>
        <entity-field :entity="agent" prop="pessoaDeficiente" :label="text('deficiencia')"></entity-field>
        <entity-field :entity="agent" prop="comunidadesTradicional" :label="text('comunidade')"></entity-field>
    </template>

    <p class="mdc-festivity-person__privacy">{{text('privado')}}</p>
</div>
