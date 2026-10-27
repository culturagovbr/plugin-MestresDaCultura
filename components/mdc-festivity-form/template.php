<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    mc-link
    mc-stepper
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
