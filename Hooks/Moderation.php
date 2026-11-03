<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\API;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Moderação documental do cadastro: uma vaga por moderador, repasse do trabalho de quem foi desabilitado, um envio
 * por parecer, devolução do "não ok" à mestra, reenvio da correção e aviso para a dona.
 */
final class Moderation
{
    static function register(Plugin $plugin): void
    {
        self::sendEachEvaluationOnce($plugin);
        self::passOnTheDisabledModeratorsWork($plugin);
        self::keepOnePlacePerModerator($plugin);
        self::returnRefusedRegistrations($plugin);
        self::reopenEvaluationOnResend($plugin);
        self::validateOnlyTheReopenedFields($plugin);
        self::showModerationToOwner($plugin);
    }

    // No programa, cada parecer é enviado uma vez só (Services\EvaluationSendGuard); na tela, uma ação por vez.
    private static function sendEachEvaluationOnce(Plugin $plugin): void
    {
        $app = App::i();
        $guard = new Services\EvaluationSendGuard;

        $app->hook('entity(RegistrationEvaluation).save:before', function () use ($plugin, $guard) {
            /** @var \MapasCulturais\Entities\RegistrationEvaluation $this */
            if ($this->id && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->includes($this->registration)) {
                $guard->check($this);
            }
        });

        // Pelo formulário (Salvar, Concluir), o parecer já enviado não muda; `uid` inexistente vale como o usuário logado.
        $app->hook('POST(registration.<<saveEvaluation|saveEvaluationAndChangeStatus>>):before', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            $registration = $this->requestedEntity;
            $user = (isset($this->postData['uid']) ? $app->repo('User')->find($this->postData['uid']) : null) ?? $app->user;

            if ($registration && !$user->is('guest') && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->includes($registration) && Services\EvaluationSendGuard::isSentBy($registration->id, $user->id)) {
                $this->errorJson(Services\EvaluationSendGuard::message(), 403);
            }
        });

        $app->hook('entity(Opportunity).sendUserEvaluations:before', function ($user) use ($plugin, $guard) {
            /** @var \MapasCulturais\Entities\Opportunity $this */
            if ($plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($this)) {
                $guard->refreshBatch($this, $user);
            }
        });

        // Na tela, uma ação por vez nos botões da avaliação (evaluation-submit-guard.js).
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'evaluation-actions' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-evaluation-submit-guard', 'js/evaluation-submit-guard.js', ['evaluation-actions']);
            }
        }, 1000);
    }

    // O trabalho do moderador desabilitado volta para a fila da moderação (Services\ModerationQueue).
    private static function passOnTheDisabledModeratorsWork(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('entity(EvaluationMethodConfigurationAgentRelation).disable:after', function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation $this */
            if ($plugin->fixedOpportunity()->matches($this->owner->opportunity)) {
                (new Services\ModerationQueue($plugin->fixedOpportunity()))->releaseFrom($this);
            }
        });

        // Antes de cada redistribuição da moderação, libera a fila da fase (ModerationQueue::releasePhase).
        $app->hook('job(' . \Opportunities\Jobs\RedistributeCommitteeRegistrations::SLUG . ').execute:before', function () use ($plugin) {
            /** @var \MapasCulturais\Entities\Job $this */
            $configuration = $this->evaluationMethodConfiguration ?? null;
            $moderation = $plugin->fixedOpportunity()->isConfigured() ? $plugin->fixedOpportunity()->get()?->evaluationMethodConfiguration : null;

            if ($configuration && $moderation && $configuration->id === $moderation->id) {
                (new Services\ModerationQueue($plugin->fixedOpportunity()))->releasePhase($configuration);
            }
        });

        // As opções da fila, logo depois das comissões, só na fase de moderação do programa com a distribuição automática.
        $app->hook('component(opportunity-phase-config-evaluation).documentary-config:before', function () use ($plugin) {
            $moderation = Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() ? $plugin->fixedOpportunity()->get()?->evaluationMethodConfiguration : null;

            if ($moderation) {
                $this->import('mdc-moderation-queue-config');
                echo "<section v-if=\"phase.id == {$moderation->id} && phase.distributionConfiguration != 'deactivate'\" class=\"evaluation-section col-12\"><mdc-moderation-queue-config :entity=\"phase\"></mdc-moderation-queue-config></section>";
            }
        });
    }

    // Cada moderador ocupa uma só vaga na comissão: recusa no servidor e aviso na tela (Services\CommitteeMembership).
    private static function keepOnePlacePerModerator(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('entity(EvaluationMethodConfigurationAgentRelation).validationErrors', function (&$errors) use ($plugin) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation $this */
            if ($this->isNew() && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($this->owner?->opportunity) && Services\CommitteeMembership::alreadyIn($this->owner, $this->group, $this->agent?->id)) {
                $errors['agent'] = [Services\CommitteeMembership::message()];
            }
        });

        $app->hook('component(<<*>>.<<*>>.opportunity-evaluation-committee).texts', function (&$texts) {
            $texts['mdcAlreadyInCommittee'] = Services\CommitteeMembership::message();
        });

        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'opportunity-evaluation-committee' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-committee-duplicate-guard', 'js/committee-duplicate-guard.js', ['opportunity-evaluation-committee']);
            }
        }, 1000);
    }

    // O "não ok" da moderação devolve o cadastro ao mestre em vez de recusá-lo.
    private static function returnRefusedRegistrations(Plugin $plugin): void
    {
        $app = App::i();

        $app->config['mailer.templates'][Services\Moderation::MAIL_RETURNED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro está pendente'),
            'template' => Services\Moderation::MAIL_RETURNED . '.html',
        ];

        // Guarda a avaliação em envio antes da aplicação do resultado.
        $sending = null;

        $app->hook('entity(RegistrationEvaluation).send:after', function () use (&$sending) {
            $sending = $this;
        }, 1);

        // Na autoaplicação, a avaliação documental recusada mantém o cadastro pendente.
        $app->hook('evaluationMethod(documentary).consolidatedAutoApplicationResult', function (&$result) use (&$sending, $plugin) {
            if ($result === Registration::STATUS_INVALID && $sending && $plugin->fixedOpportunity()->includes($sending->registration)) {
                $result = Registration::STATUS_SENT;
                // Sem mudança de status, a inscrição não é copiada para a fase seguinte.
                $sending->registration->skipSync = true;
            }
        });

        $app->hook('entity(RegistrationEvaluation).send:after', function () use ($plugin) {
            /** @var \MapasCulturais\Entities\RegistrationEvaluation $this */
            $registration = $this->registration;

            if ($plugin->fixedOpportunity()->includes($registration) && $registration->status === Registration::STATUS_SENT) {
                (new Services\Moderation)->returnToOwner($registration, $this);
            }
        });
    }

    // No reenvio da correção, a avaliação volta a quem avaliou e o mestre recebe a confirmação.
    private static function reopenEvaluationOnResend(Plugin $plugin): void
    {
        $app = App::i();

        $app->config['mailer.templates'][Services\Moderation::MAIL_RESENT] = [
            'title' => i::__('[Rede de Mestras e Mestres] Cadastro corrigido para nova análise'),
            'template' => Services\Moderation::MAIL_RESENT . '.html',
        ];
        $app->config['mailer.templates'][Services\Moderation::MAIL_CORRECTION_RECEIVED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Recebemos a sua correção'),
            'template' => Services\Moderation::MAIL_CORRECTION_RECEIVED . '.html',
        ];

        $app->hook('entity(Registration).sendEditableFields:after', function () use ($plugin) {
            /** @var Registration $this */
            if ($plugin->fixedOpportunity()->includes($this)) {
                $moderation = new Services\Moderation;
                $moderation->sendBackToValuers($this);
                $moderation->confirmResend($this);
            }
        });
    }

    // Na correção do cadastro devolvido, dos erros de campo do formulário ficam só os dos campos reabertos, nos
    // metadados e no envio; os demais erros seguem.
    private static function validateOnlyTheReopenedFields(Plugin $plugin): void
    {

        $onlyReopened = function (array &$errors) use ($plugin) {
            /** @var Registration $this */
            if (!$plugin->fixedOpportunity()->isConfigured() || !$plugin->fixedOpportunity()->includes($this) || !Services\Moderation::isPending($this)) {
                return;
            }

            $reopened = (array) $this->editableFields;
            $errors = array_filter($errors, function ($key) use ($reopened) {
                $name = preg_replace('/^file_/', 'rfc_', (string) $key);

                return !preg_match('/^(field|rfc)_\d+$/', $name) || in_array($name, $reopened, true);
            }, ARRAY_FILTER_USE_KEY);
        };

        // Roda depois das validações de campo no envio de Hooks\RegistrationForm.
        App::i()->hook('entity(Registration).<<validationErrors|sendValidationErrors>>', $onlyReopened, 100);
    }

    // Resultado da moderação para o mestre no `tabs-alert`, acima das abas da inscrição: em análise, devolvido com o
    // resultado de cada campo, ou aprovado com o certificado.
    private static function showModerationToOwner(Plugin $plugin): void
    {
        $app = App::i();

        $app->hook('template(registration.view.single-tab):begin', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $registration = $this->controller->requestedEntity;

            if (!$registration instanceof Registration || !$plugin->fixedOpportunity()->includes($registration)) {
                return;
            }

            if ($app->user->is('guest') || $registration->owner?->user?->id !== $app->user->id) {
                return;
            }

            $moderation = (new Services\Moderation)->pendingView($registration);

            // Em cada situação, o selo do topo da ficha diz o mesmo que o aviso, no lugar de "Enviada".
            if ($moderation) {
                Services\ProgramVocabulary::apply(['Enviada' => i::__('Pendente de moderação')]);
            }

            // Em análise: o aviso do que acontece depois.
            if (!$moderation && Services\Moderation::underReview($registration->status, $registration->editableFields, $registration->editSentTimestamp)) {
                Services\ProgramVocabulary::apply(['Enviada' => i::__('Em análise')]);
                $moderation = ['status' => 'review'];
            }

            if (!$moderation && $registration->status === Registration::STATUS_APPROVED && ($seal = $plugin->programSeal()->relationOf($registration))) {
                Services\ProgramVocabulary::apply(['Enviada' => i::__('Aprovado')]);
                $moderation = ['status' => 'approved', 'certificateUrl' => $seal->singleUrl];
            }

            if (!$moderation) {
                return;
            }

            $this->import('mdc-moderation-notice');
            $json = htmlspecialchars(json_encode($moderation), ENT_QUOTES);

            echo <<<HTML
                <template #tabs-alert>
                    <mdc-moderation-notice :moderation="{$json}"></mdc-moderation-notice>
                </template>
            HTML;
        });
    }
}
