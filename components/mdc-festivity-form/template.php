<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    entity-field
    entity-file
    entity-files-list
    entity-gallery
    entity-gallery-video
    entity-links
    entity-profile
    mc-alert
    mc-container
    mc-icon
    mc-link
    mc-stepper
    mdc-festivity-agenda
    mdc-festivity-checklist
    mdc-festivity-classification
    mdc-festivity-masters
    mdc-festivity-person
    mdc-festivity-years
');
?>
<div class="mdc-festivity-form">
    <header class="mdc-festivity-form__header">
        <h1 class="mdc-festivity-form__title">{{text('titulo')}}</h1>
        <p class="mdc-festivity-form__intro">{{text('apresentacao')}}</p>
    </header>

    <mc-stepper :steps="labels" :step="step" only-active-label :disabled-steps="disabledSteps" @step-changed="goTo"></mc-stepper>

    <mc-container>
        <main class="mdc-festivity-form__main">
            <section class="mdc-festivity-form__step" aria-labelledby="mdc-festivity-step">
                <h2 v-if="step === 5" id="mdc-festivity-step" class="mdc-festivity-form__step-title" data-mdc-section="apresentacao-e-portfolio" tabindex="-1" ref="stepTitle">{{text('cartao apresentacao')}}</h2>
                <h2 v-else id="mdc-festivity-step" class="mdc-festivity-form__step-title" tabindex="-1" ref="stepTitle">{{labels[step]}}</h2>

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
                    <mdc-festivity-agenda ref="agenda" :entity="entity" :required="isRequired('occurrences')" @change="agendaChanged"></mdc-festivity-agenda>
                    <entity-field :entity="entity" prop="mdcFestejoTerritorio" :label="text('territorio')" :required="isRequired('mdcFestejoTerritorio')"></entity-field>
                    <entity-field :entity="entity" prop="mdcFestejoLei" :label="text('lei')"></entity-field>
                    <entity-file v-if="entity.mdcFestejoLei === 'Sim'" :entity="entity" group-name="mdcLegislacao" :title="text('arquivo da lei')" editable required></entity-file>
                </template>

                <template v-else-if="step === 2">
                    <mdc-festivity-checklist :entity="entity" prop="mdcFestejoEconomia" other-prop="mdcFestejoEconomiaOutra" :groups="lists.economy" :title="text('economia')" :description="text('economia dica')"></mdc-festivity-checklist>

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
                            <mdc-festivity-years v-if="entity[source.key] === 'Sim'" :entity="entity" :prop="source.key + 'Anos'" required></mdc-festivity-years>
                        </template>
                    </fieldset>
                </template>

                <template v-else-if="step === 3">
                    <entity-field :entity="entity" prop="mdcFestejoRealizador" :label="text('realizador')" :required="isRequired('mdcFestejoRealizador')"></entity-field>
                    <template v-if="producers.institution.includes(entity.mdcFestejoRealizador)">
                        <entity-field :entity="entity" prop="mdcFestejoInstituicao" :label="text('instituicao')" :required="true"></entity-field>
                        <entity-field v-if="producers.cnpj.includes(entity.mdcFestejoRealizador)" :entity="entity" prop="mdcFestejoCnpj" :label="text('cnpj')" :field-description="text('cnpj dica')" :required="true"></entity-field>
                        <entity-field :entity="entity" prop="mdcFestejoInstituicaoPonto" :label="text('instituicao ponto')"></entity-field>
                    </template>
                    <mdc-festivity-person ref="person" part="contact"></mdc-festivity-person>
                </template>

                <template v-else-if="step === 4">
                    <mdc-festivity-person ref="person" part="markers"></mdc-festivity-person>
                </template>

                <template v-else-if="step === 5">
                    <div class="field mdc-festivity-form__picture">
                        <span class="field__title">{{text('foto principal')}}</span>
                        <entity-profile :entity="entity"></entity-profile>
                    </div>
                    <entity-field v-for="(label, prop, index) in socialNetworks" :key="prop" :entity="entity" :prop="prop" :label="label" :field-description="index === 0 ? text('redes dica') : ''"></entity-field>
                    <entity-files-list :entity="entity" group="downloads" :title="text('portfolio')" editable></entity-files-list>
                    <div class="field">
                        <span class="field__title">{{text('fotos')}}</span>
                        <entity-gallery :entity="entity" editable></entity-gallery>
                    </div>
                    <div class="field">
                        <span class="field__title">{{text('videos')}}</span>
                        <entity-gallery-video :entity="entity" editable></entity-gallery-video>
                    </div>
                    <div class="field">
                        <span class="field__title">{{text('links')}}</span>
                        <entity-links :entity="entity" editable></entity-links>
                    </div>
                </template>
            </section>

            <section v-if="step === 5" class="mdc-festivity-form__step" aria-labelledby="mdc-festivity-consent">
                <h3 id="mdc-festivity-consent" class="mdc-festivity-form__step-title" data-mdc-section="termo-de-responsabilidade-lgpd">{{text('aceite titulo')}}</h3>
                <div class="field mdc-festivity-form__consent" :class="{error: entity.__validationErrors?.mdcFestejoAceite}">
                    <label class="mdc-festivity-form__consent-option">
                        <input type="checkbox" :checked="!!entity.mdcFestejoAceite" @change="setConsent($event.target.checked)">
                        <span>{{text('aceite')}} <span class="required">*{{text('obrigatorio')}}</span></span>
                    </label>
                    <small v-for="error in entity.__validationErrors?.mdcFestejoAceite || []" class="field__error">{{error}}</small>
                </div>
            </section>

            <mc-alert v-if="step === 5" type="success">{{text('aviso calendario')}}</mc-alert>
        </main>

        <aside>
            <div class="registration-actions mdc-festivity-form__actions">
                <div class="registration-actions__primary">
                    <div v-if="messages.length" class="registration-actions__errors" role="alert">
                        <span class="registration-actions__errors-title">{{text('falta')}}</span>
                        <ul class="mdc-festivity-form__missing">
                            <li v-for="message in messages" :key="message">{{message}}</li>
                        </ul>
                    </div>

                    <div v-if="isLast" class="registration-actions__validation">
                        <div v-if="entity.status <= 0" class="registration-actions__alert">
                            <div class="registration-actions__alert-header">
                                <mc-icon name="exclamation"></mc-icon>
                                <span class="bold">{{text('atencao obrigatorios')}}</span>
                            </div>
                            <div class="registration-actions__alert-content">
                                <span>{{text('so publica completo')}}</span>
                            </div>
                        </div>
                        <button type="button" class="button button--large button--xbg button--primary button--icon registration-actions__send" :disabled="saving" @click="publish">
                            {{text('publicar')}}
                            <mc-icon name="send"></mc-icon>
                        </button>
                    </div>
                </div>

                <div v-if="!isLast || step > 0 || !entity.id" class="registration-actions__secondary">
                    <button v-if="!isLast" type="button" class="button button--bg button--large button--primary button--icon" :disabled="saving" @click="next">
                        {{text('salvar e avancar')}}
                        <mc-icon name="arrow-right"></mc-icon>
                    </button>
                    <button v-if="step > 0" type="button" class="button button--md button--large button--primary-outline button--icon" :disabled="saving" @click="goTo(step - 1)">
                        <mc-icon name="arrow-left"></mc-icon>
                        {{text('etapa anterior')}}
                    </button>
                    <mc-link v-else-if="!entity.id" route="panel/index" class="button button--md button--large button--primary-outline">{{text('voltar ao painel')}}</mc-link>
                </div>

                <div class="registration-actions__save-buttons">
                    <button type="button" class="button button--sm button--large button--primary" :disabled="saving" @click="saveStep">{{text('salvar')}}</button>

                    <!-- Salva e confirma a saída; o festejo publicado só sai com as 6 etapas. -->
                    <mc-modal :title="text('sair titulo')" classes="mdc-festivity-form__exit">
                        <template #default>
                            <p>{{entity.status > 0 ? text('sair publicado') : text('sair rascunho')}}</p>
                        </template>

                        <template #button="modal">
                            <button type="button" class="button button--sm button--large button--primary" :disabled="saving" @click="saveAndExit(modal)">{{text('salvar e sair')}}</button>
                        </template>

                        <template #actions="modal">
                            <button type="button" class="button button--text" @click="modal.close()">{{text('continuar editando')}}</button>
                            <mc-link route="panel/events" :hash="entity.status > 0 ? 'publish' : 'draft'" class="button button--primary">{{text('sair')}}</mc-link>
                        </template>
                    </mc-modal>
                </div>
            </div>
        </aside>
    </mc-container>
</div>
