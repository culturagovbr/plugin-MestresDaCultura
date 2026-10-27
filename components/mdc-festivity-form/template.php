<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    entity-file
    mc-link
    mc-stepper
    mdc-festivity-agenda
    mdc-festivity-checklist
    mdc-festivity-classification
    mdc-festivity-masters
');
?>
<div class="mdc-festivity-form">
    <header class="mdc-festivity-form__header">
        <h1 class="mdc-festivity-form__title">{{text('titulo')}}</h1>
        <p class="mdc-festivity-form__intro">{{text('apresentacao')}}</p>
    </header>

    <mc-stepper :steps="labels" :step="step" only-active-label :disabled-steps="disabledSteps" @step-changed="goTo"></mc-stepper>

    <section class="mdc-festivity-form__step" aria-labelledby="mdc-festivity-step">
        <h2 id="mdc-festivity-step" class="mdc-festivity-form__step-title" tabindex="-1" ref="stepTitle">{{labels[step]}}</h2>

        <template v-if="step === 0">
            <entity-field :entity="entity" prop="name" :label="text('nome')" :required="isRequired('name')"></entity-field>
            <entity-field :entity="entity" prop="longDescription" :label="text('sobre')" :required="isRequired('longDescription')"></entity-field>
            <mdc-festivity-classification :entity="entity" :required="isRequired('mdcFestejoSegmento')"></mdc-festivity-classification>
            <div class="mdc-festivity-form__row">
                <entity-field :entity="entity" prop="mdcFestejoPrimeiroAno" :label="text('primeiro ano')" :min="1500" :max="currentYear"></entity-field>
                <entity-field :entity="entity" prop="mdcFestejoEdicoes" :label="text('edicoes')" :min="1"></entity-field>
            </div>
            <entity-field :entity="entity" prop="mdcFestejoPeriodoHabitual" :label="text('periodo habitual')" :field-description="text('periodo habitual dica')"></entity-field>
            <entity-field :entity="entity" prop="mdcFestejoPorte" :label="text('porte')" :field-description="text('porte dica')" :required="isRequired('mdcFestejoPorte')"></entity-field>
            <entity-field :entity="entity" prop="mdcFestejoTipoLocal" :label="text('onde')" :field-description="text('onde dica')" :required="isRequired('mdcFestejoTipoLocal')"></entity-field>
        </template>

        <template v-else-if="step === 1">
            <mdc-festivity-agenda :entity="entity" :required="isRequired('occurrences')"></mdc-festivity-agenda>
            <entity-field :entity="entity" prop="mdcFestejoTerritorio" :label="text('territorio')" :required="isRequired('mdcFestejoTerritorio')"></entity-field>
            <entity-field :entity="entity" prop="mdcFestejoLei" :label="text('lei')"></entity-field>
            <entity-file v-if="entity.mdcFestejoLei === 'Sim'" :entity="entity" group-name="mdcLegislacao" :title="text('arquivo da lei')" editable required></entity-file>
        </template>

        <template v-else-if="step === 2">
            <mdc-festivity-checklist :entity="entity" prop="mdcFestejoEconomia" other-prop="mdcFestejoEconomiaOutra" :groups="lists.economy" collapsible :title="text('economia')" :description="text('economia dica')"></mdc-festivity-checklist>

            <entity-field :entity="entity" prop="mdcFestejoComMestres" :label="text('com mestres')"></entity-field>
            <mdc-festivity-masters v-if="entity.mdcFestejoComMestres === 'Sim'" :entity="entity"></mdc-festivity-masters>

            <entity-field :entity="entity" prop="mdcFestejoPonto" :label="text('ponto')"></entity-field>
            <entity-field v-if="entity.mdcFestejoPonto === 'Sim'" :entity="entity" prop="mdcFestejoPontoNome" :label="text('ponto nome')" :required="true"></entity-field>

            <entity-field :entity="entity" prop="mdcFestejoTemAcessibilidade" :label="text('tem acessibilidade')"></entity-field>
            <mdc-festivity-checklist v-if="entity.mdcFestejoTemAcessibilidade === 'Sim'" :entity="entity" prop="mdcFestejoAcessibilidade" other-prop="mdcFestejoAcessibilidadeOutra" :groups="lists.accessibility" :title="text('acessibilidade')" required></mdc-festivity-checklist>

            <entity-field :entity="entity" prop="mdcFestejoTemAcolhimento" :label="text('tem acolhimento')"></entity-field>
            <mdc-festivity-checklist v-if="entity.mdcFestejoTemAcolhimento === 'Sim'" :entity="entity" prop="mdcFestejoAcolhimento" other-prop="mdcFestejoAcolhimentoOutro" :groups="lists.shelter" :title="text('acolhimento')" required></mdc-festivity-checklist>

            <fieldset class="mdc-festivity-form__funding">
                <legend class="field__title">{{text('recursos')}}</legend>
                <template v-for="source in funding" :key="source.key">
                    <entity-field :entity="entity" :prop="source.key" :label="source.question"></entity-field>
                    <entity-field v-if="entity[source.key] === 'Sim'" :entity="entity" :prop="source.key + 'Anos'" :label="text('anos')" :field-description="text('anos dica')" :required="true"></entity-field>
                </template>
            </fieldset>
        </template>
    </section>

    <div v-if="messages.length" class="mdc-festivity-form__missing" role="alert">
        <p>{{text('falta')}}</p>
        <ul>
            <li v-for="message in messages" :key="message">{{message}}</li>
        </ul>
    </div>

    <div class="mdc-festivity-form__actions">
        <mc-link v-if="step === 0" route="panel/index" class="button button--primary-outline">{{text('voltar ao painel')}}</mc-link>
        <button v-else type="button" class="button button--primary-outline" :disabled="saving" @click="goTo(step - 1)">{{text('etapa anterior')}}</button>

        <button v-if="!isLast" type="button" class="button button--primary" :disabled="saving" @click="next">{{text('salvar e avancar')}}</button>
        <button v-else type="button" class="button button--primary" :disabled="saving" @click="publish">{{text('publicar')}}</button>
    </div>
</div>
