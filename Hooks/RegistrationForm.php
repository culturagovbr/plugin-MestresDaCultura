<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Formulário do cadastro dos Mestres: Atuação e festejo no entity-field, validações do envio, leitura da ficha
 * (v1, fotos, PDF), seções, idade, caminho de navegação, uma inscrição por pessoa e links do agente sem mexer nos
 * vídeos.
 */
final class RegistrationForm
{
    /** Telas do v1 que só exibem a inscrição; as que salvam não entram aqui. */
    private const V1_READING_ACTIONS = ['registrationview', 'registrationevaluationtionformview'];

    static function register(Plugin $plugin): void
    {
        self::renderActingField($plugin);
        self::validateActingFieldOnSend($plugin);
        self::validatePrizeIdOnSend($plugin);
        self::freezeActingFieldOnSend($plugin);
        self::showFieldsOnV1Reading($plugin);
        self::renderPhotoViewer($plugin);
        self::showActingFieldAsTextInPdf($plugin);
        self::limitToOneRegistrationPerUser($plugin);
        self::fixTheProgramBreadcrumbs($plugin);
        self::markFormSections($plugin);
        self::showAgeInForm($plugin);
        self::saveOnlyTheLinksFromTheLinksField($plugin);
    }

    // No programa, o campo de links do agente grava só os links (Services\AgentLinks); os vídeos do agente ficam.
    private static function saveOnlyTheLinksFromTheLinksField(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('controller(opportunity).registerFieldType(agent-owner-field)', function ($field, &$config) use ($app, $plugin) {
            /** @var Opportunity $this */
            if (($field->config['entityField'] ?? null) !== '@links' || !$plugin->fixedOpportunity()->matches($this)) {
                return;
            }

            $config['serialize'] = function ($value, ?Registration $registration = null) use ($app) {
                if ($owner = $registration?->owner) {
                    Services\AgentLinks::save($owner, $value);
                    $app->applyHookBoundTo($owner, 'registrationFieldTypes.saveToEntity', ['entity_field' => '@links', 'value' => $value]);
                }

                return is_object($value) || is_array($value) ? json_encode($value) : $value;
            };
        });
    }

    // A Atuação e o festejo desenhados dentro do `entity-field`, em toda página, com o valor devolvido como evento de
    // input para o salvamento automático.
    private static function renderActingField(Plugin $plugin): void
    {
        App::i()->hook('template(<<*>>.<<*>>.entity-field-label):after', function () {
            $this->import('mdc-acting-field mdc-festivity-field');

            $slug = Services\ActingField::SLUG;
            $festivity = Services\FestivityField::SLUG;

            // O festejo do Calendário também: componente próprio dentro do campo do core.
            echo <<<HTML
                <mdc-acting-field v-if="is('{$slug}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    :field-label="label || description.label" @change="change({ target: { value: \$event } }, true)"></mdc-acting-field>
                <mdc-festivity-field v-if="is('{$festivity}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    :field-id="propId" @change="change({ target: { value: \$event } }, true)"></mdc-festivity-field>
            HTML;
        });
    }

    // No envio, valida a Atuação pela árvore e recusa o rascunho com item tirado da lista desde a última gravação.
    private static function validateActingFieldOnSend(Plugin $plugin): void
    {
        App::i()->hook('entity(Registration).sendValidationErrors', function (&$errors) {
            /** @var Registration $this */
            foreach (Services\ActingField::fieldsOf($this) as $field) {
                $value = $this->{$field->fieldName};

                if ($value === null) {
                    continue;
                }

                if (!Services\ActingField::isValid($value)) {
                    $errors[$field->fieldName][] = Services\ActingField::invalidMessage();
                } elseif ($this->status === Registration::STATUS_DRAFT && $removed = Services\ActingField::removedFromTree($value)) {
                    $errors[$field->fieldName][] = Services\ActingField::staleMessage($removed);
                }
            }
        });
    }

    // No envio, o premiado pelo MinC informa o ID do painel ou marca que não o localizou (Services\PrizeId).
    private static function validatePrizeIdOnSend(Plugin $plugin): void
    {
        App::i()->hook('entity(Registration).sendValidationErrors', function (&$errors) {
            /** @var Registration $this */
            $fields = Services\PrizeId::fieldsOf($this);

            if ($fields && Services\PrizeId::isMissing($this->{$fields['id']->fieldName}, $this->{$fields['notFound']->fieldName})) {
                $errors[$fields['id']->fieldName][] = Services\PrizeId::message();
            }
        });
    }

    // Regrava o espelho com os nomes do momento do envio; daí em diante, ele não muda mais.
    private static function freezeActingFieldOnSend(Plugin $plugin): void
    {
        App::i()->hook('entity(Registration).send:before', function () {
            /** @var Registration $this */
            $this->registerFieldsMetadata();

            foreach (Services\ActingField::fieldsOf($this) as $field) {
                $this->{$field->fieldName} = $this->{$field->fieldName};
            }
        });
    }

    // Nas telas de leitura do v1, a Atuação e as fotos e vídeos vão montados em HTML, com o CSS e o JS do plugin.
    private static function showFieldsOnV1Reading(Plugin $plugin): void
    {
        $app = App::i();

        $isV1Reading = function () use ($app): bool {
            $controller = $app->view->controller ?? null;

            return $controller?->id === 'embedtools' && in_array($controller->action, self::V1_READING_ACTIONS, true);
        };

        $app->hook('view.render(<<*>>):before', function () use ($app, $isV1Reading) {
            if ($isV1Reading()) {
                $app->view->enqueueStyle('app', 'mdc-v1-reading', 'css/plugin-MDC.css', ['main']);
                $app->view->enqueueScript('app', 'mdc-v1-reading', 'js/plugin-MDC.js');
            }
        });

        $app->hook('entity(Registration).jsonSerialize', function (&$result) use ($isV1Reading, $plugin) {
            /** @var Registration $this */
            if (!$isV1Reading()) {
                return;
            }

            // A atuação vai em cartões, a partir do espelho gravado.
            foreach (Services\ActingField::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\ActingField::toHtml($result[$field->fieldName]);
                }
            }

            // Fotos e vídeos do agente em HTML (AgentMediaView), só na oportunidade do programa.
            if (!$plugin->fixedOpportunity()->includes($this)) {
                return;
            }

            foreach (Services\AgentMediaView::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\AgentMediaView::toHtml($field, $result[$field->fieldName], $this->owner);
                }
            }
        });
    }

    // A foto clicada na ficha do v1 abre na página de cima.
    private static function renderPhotoViewer(Plugin $plugin): void
    {
        App::i()->hook('template(<<*>>.<<*>>.main-header):after', function () {
            $this->import('mdc-photo-viewer');

            echo '<mdc-photo-viewer></mdc-photo-viewer>';
        });
    }

    // No PDF da ficha, a Atuação sai como texto em trilhas, uma por linha.
    private static function showActingFieldAsTextInPdf(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('entity(Opportunity).registrationMetadata', function () use ($app) {
            /** @var Opportunity $this */
            $controller = $app->view->controller ?? null;

            if ($controller?->id !== 'registration' || $controller->action !== 'exportPDF') {
                return;
            }

            $definitions = $app->getRegisteredMetadata(Registration::class);

            foreach ($this->registrationFieldConfigurations as $field) {
                if ($field->fieldType === Services\ActingField::SLUG && isset($definitions[$field->fieldName])) {
                    $definitions[$field->fieldName]->unserialize = [Services\ActingField::class, 'unserializeAsText'];
                }
                // O festejo pelo nome, e não pelo id.
                if ($field->fieldType === Services\FestivityField::SLUG && isset($definitions[$field->fieldName])) {
                    $definitions[$field->fieldName]->unserialize = [Services\FestivityField::class, 'toText'];
                }
            }
        });
    }

    // Uma inscrição por pessoa, e não por agente responsável.
    private static function limitToOneRegistrationPerUser(Plugin $plugin): void
    {
        App::i()->hook('entity(Registration).validations', function (&$validations) {
            $validations['owner']['\MDC\Plugin::isTheOnlyRegistrationOfTheUser($this)'] =
                i::__('Você já tem um cadastro nesta oportunidade.');
        });
    }

    // Caminho de navegação do programa na inscrição e na avaliação, antes de ir para a tela (Services\ProgramBreadcrumb).
    private static function fixTheProgramBreadcrumbs(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        $app->hook('mapas.printJsObject:before', function () use ($fixedOpportunity) {
            $controller = $this->controller;
            $registration = $controller->requestedEntity ?? null;
            // Lê como lista a propriedade mágica do tema.
            $current = array_values((array) $this->breadcrumb);

            if (!Plugin::isTheme() || $controller->id !== 'registration' || !$registration instanceof Registration || !$current
                || !$fixedOpportunity->isConfigured() || !$fixedOpportunity->includes($registration)) {
                return;
            }

            if ($controller->action === 'evaluation') {
                $breadcrumb = Services\ProgramBreadcrumb::evaluation($current);
            } else {
                $isForm = $controller->action === 'registrationEdit' || (end($current)['label'] ?? null) === i::__('Formulário');
                $breadcrumb = Services\ProgramBreadcrumb::registration($registration->singleUrl, $isForm);
            }

            $this->breadcrumb = $breadcrumb;

            if (isset($this->jsObject['breadcrumb'])) {
                $this->jsObject['breadcrumb'] = $breadcrumb;
            }
        });
    }

    // No subsite, as seções do formulário ganham o nome como marca, para o ícone de cada uma (mdc-section-marks).
    private static function markFormSections(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('component(registration-form):end', function () use ($app) {
            if (Plugin::isTheme()) {
                $this->import('mdc-section-marks');
                echo '<mdc-section-marks></mdc-section-marks>';
            }
        });
    }

    // A idade calculada da data de nascimento, abaixo do campo do formulário que grava nela (ver o componente mdc-age).
    private static function showAgeInForm(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('template(<<*>>.<<*>>.entity-field):end', function () use ($app) {
            if (Plugin::isTheme()) {
                $this->import('mdc-age');
                echo '<mdc-age v-if="description?.registrationFieldConfiguration?.config?.entityField === \'dataDeNascimento\'" :value="entity[prop]"></mdc-age>';
            }
        });
    }
}
