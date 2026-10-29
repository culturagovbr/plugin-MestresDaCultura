<?php

namespace MDC;

use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Definitions\FileGroup;
use MapasCulturais\Definitions\RegistrationFieldType;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

// O autoloader tem mapa plano e registra temas antes de plugins: repetir `MestresDaCultura` faria
// o plugin ser ignorado sem erro, e nenhuma classe ou subpasta pode conter `MDC` no nome.
class Plugin extends \MapasCulturais\Plugin
{
    /** Povos e comunidades da planilha do programa que a lista do core não tem. */
    const TRADITIONAL_COMMUNITIES = ['Castanheiras', 'Isqueiros', 'Piaçaveiros', 'Seringueiros'];

    /** Telas do painel que o subsite não usa: não abrem para ninguém, nem para o saasSuperAdmin (ver o menu do tema). */
    const UNUSED_PANEL_SCREENS = ['spaces', 'apps', 'my-app', 'validations', 'accountability'];

    /** Telas do v1 que só exibem a inscrição; as que salvam não entram aqui. */
    private const V1_READING_ACTIONS = ['registrationview', 'registrationevaluationtionformview'];

    private ?Services\FixedOpportunity $fixedOpportunity = null;

    private ?Services\ProgramSeal $programSeal = null;

    private ?Services\FestivitySeal $festivitySeal = null;

    function register()
    {
        $app = App::i();

        // Ids da oportunidade e dos selos, do ambiente e sem padrão; valem também nos jobs da linha de comando.
        $app->config[Services\FixedOpportunity::CONFIG_KEY] ??= env('MDC_OPPORTUNITY_ID');
        $app->config[Services\ProgramSeal::CONFIG_KEY] ??= env('MDC_SEAL_ID');
        $app->config[Services\FestivitySeal::CONFIG_KEY] ??= env('MDC_FESTIVITY_SEAL_ID');

        $app->registerController('mdc', Controller::class);
        $app->registerRegistrationFieldType(new RegistrationFieldType(Services\ActingField::definition()));
        $app->registerRegistrationFieldType(new RegistrationFieldType(Services\FestivityField::definition()));
        $app->registerJobType(new Jobs\ReleaseStaleDrafts(Jobs\ReleaseStaleDrafts::SLUG));
        $this->registerModerationQueueOptions();

        // Públicos, para a busca de Mestras e Mestres (ver Services\MasterPublicData).
        foreach (Services\MasterPublicData::metadata() as $key => $definition) {
            $this->registerAgentMetadata($key, $definition);
        }

        // O festejo é um Evento do core (ver Services\FestivityFields).
        foreach (Services\FestivityFields::definitions() as $key => $definition) {
            $this->registerEventMetadata($key, $definition);
        }
        $app->registerFileGroup('event', new FileGroup(Services\FestivityFields::LAW_FILE, ['^application/pdf$', '^image/(jpeg|png)$'], i::__('Envie a lei em PDF ou em imagem (JPG ou PNG).'), true));
    }

    // Opções da devolução dos rascunhos de moderação (ver Services\ModerationQueue), válidas também fora do subsite.
    private function registerModerationQueueOptions(): void
    {
        [$min, $max] = Services\ModerationQueue::STALE_DAYS_RANGE;

        $this->registerEvauationMethodConfigurationMetadata(Services\ModerationQueue::RELEASE_DISABLED, [
            'label' => i::__('Devolver à fila os rascunhos de moderadores desabilitados'),
            'type' => 'boolean',
            'default' => true,
        ]);

        $this->registerEvauationMethodConfigurationMetadata(Services\ModerationQueue::RELEASE_STALE, [
            'label' => i::__('Devolver à fila os rascunhos parados'),
            'type' => 'boolean',
            'default' => true,
        ]);

        $this->registerEvauationMethodConfigurationMetadata(Services\ModerationQueue::STALE_DAYS, [
            'label' => i::__('Dias sem alteração para o rascunho voltar à fila'),
            'type' => 'integer',
            'default' => Services\ModerationQueue::DEFAULT_STALE_DAYS,
            'validations' => [
                "v::intVal()->between({$min}, {$max})" => sprintf(i::__('Informe uma quantidade de dias entre %d e %d.'), $min, $max),
            ],
        ]);
    }

    /** As opções de "não" (não pertence, não informado) ficam no topo; o resto, em ordem alfabética. */
    static function withTraditionalCommunities(array $options): array
    {
        $options += array_combine(self::TRADITIONAL_COMMUNITIES, self::TRADITIONAL_COMMUNITIES);
        $isNo = fn ($key) => $key === '' || str_starts_with(Services\TreeText::comparable($key), 'nao ');
        $first = array_filter($options, $isNo, ARRAY_FILTER_USE_KEY);
        $rest = array_diff_key($options, $first);
        uksort($rest, fn ($a, $b) => strcmp(Services\TreeText::comparable($a), Services\TreeText::comparable($b)));

        return $first + $rest;
    }

    function _init()
    {
        Hooks\Tree::register($this);
        $this->renderActingField();
        $this->validateActingFieldOnSend();
        $this->validatePrizeIdOnSend();
        $this->freezeActingFieldOnSend();
        $this->showFieldsOnV1Reading();
        $this->renderPhotoViewer();
        $this->showActingFieldAsTextInPdf();
        Hooks\Management::register($this);
        Hooks\PanelMenu::register($this);
        Hooks\SubsiteIsolation::register($this);
        Hooks\FixedOpportunityLock::register($this);
        $this->limitToOneRegistrationPerUser();
        Hooks\Masters::register($this);
        $this->sendEachEvaluationOnce();
        $this->passOnTheDisabledModeratorsWork();
        $this->fixTheProgramBreadcrumbs();
        Hooks\Festivities::register($this);
        $this->speakOfCadastro();
        $this->sendTheNetworkMails();
        $this->keepOnePlacePerModerator();
        $this->returnRefusedRegistrations();
        $this->reopenEvaluationOnResend();
        $this->validateOnlyTheReopenedFields();
        $this->showModerationToOwner();
        $this->applyProgramSeal();
        $this->markFormSections();
        $this->showAgeInForm();
        $this->notifyApproval();
        $this->allowFinalPhaseSync();
    }

    // A aprovação na moderação vem da autoaplicação, no envio da avaliação, e o core agenda a cópia da inscrição
    // para a fase final em nome de quem mudou o status: o avaliador, que não controla a oportunidade, e a cópia
    // falha. Só neste job e só na oportunidade fixa, a cópia roda sem a checagem. O `execute:after` do job não
    // roda quando ele falha; o `app.executeJob:after` roda sempre.
    private function allowFinalPhaseSync(): void
    {
        $app = App::i();
        $plugin = $this;
        $disabled = false;

        $app->hook('job(' . \OpportunityPhases\Jobs\SyncPhaseRegistrations::SLUG . ').execute:before', function () use ($app, $plugin, &$disabled) {
            /** @var \MapasCulturais\Entities\Job $this */
            if ($plugin->fixedOpportunity()->matches($this->opportunity)) {
                $app->disableAccessControl();
                $disabled = true;
            }
        });

        $app->hook('app.executeJob:after', function () use ($app, &$disabled) {
            if ($disabled) {
                $app->enableAccessControl();
                $disabled = false;
            }
        });
    }

    // Ver Services\CommitteeMembership. Como no Cultura Viva: recusa no servidor e aviso na tela, que o componente do
    // core não mostra o erro da API.
    private function keepOnePlacePerModerator(): void
    {
        $app = App::i();
        $plugin = $this;

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

    // Troca o template de e-mail do core pelo da Rede nos cadastros do programa (ver Services\ProgramMails).
    private function sendTheNetworkMails(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->config['mailer.templates'][Services\ProgramMails::STARTED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro foi iniciado'),
            'template' => Services\ProgramMails::STARTED . '.html',
        ];
        $app->config['mailer.templates'][Services\ProgramMails::SENT] = [
            'title' => i::__('[Rede de Mestras e Mestres] Recebemos o seu cadastro'),
            'template' => Services\ProgramMails::SENT . '.html',
        ];

        $app->hook('sendMailNotification.<<registrationStart:beforeEnqueue|registrationSend>>', function ($registration, &$template) use ($fixedOpportunity) {
            if (isset(Services\ProgramMails::REPLACES[$template]) && $fixedOpportunity->isConfigured() && $fixedOpportunity->includes($registration)) {
                $template = Services\ProgramMails::REPLACES[$template];
            }
        });
    }

    // Aplica o vocabulário do programa no subsite dos Mestres, em app.init:after (ver Services\ProgramVocabulary).
    private function speakOfCadastro(): void
    {
        $app = App::i();

        $app->hook('app.init:after', function () use ($app) {
            if (!Plugin::isTheme()) {
                return;
            }

            Services\ProgramVocabulary::apply(Services\ProgramVocabulary::FESTIVITY_PHRASES);

            if (Services\ProgramVocabulary::appliesTo($app->user)) {
                Services\ProgramVocabulary::apply();
            }
        });
    }

    // Ver Services\ProgramBreadcrumb. As views do core montam o caminho no corpo; aqui, antes de ele ir para a tela,
    // como o Cultura Viva faz no dele (mapas.printJsObject:before). O componente já pode ter copiado o caminho.
    private function fixTheProgramBreadcrumbs(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->hook('mapas.printJsObject:before', function () use ($fixedOpportunity) {
            $controller = $this->controller;
            $registration = $controller->requestedEntity ?? null;
            // Propriedade mágica do tema: o `empty()` direto nela não a enxerga.
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

    // Ver Services\ModerationQueue. O reenvio da correção com o moderador desabilitado fica em Services\Moderation.
    private function passOnTheDisabledModeratorsWork(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->hook('entity(EvaluationMethodConfigurationAgentRelation).disable:after', function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation $this */
            if ($plugin->fixedOpportunity()->matches($this->owner->opportunity)) {
                (new Services\ModerationQueue($plugin->fixedOpportunity()))->releaseFrom($this);
            }
        });

        // Antes de cada redistribuição da moderação, como no Cultura Viva (ver ModerationQueue::releasePhase).
        $app->hook('job(' . \Opportunities\Jobs\RedistributeCommitteeRegistrations::SLUG . ').execute:before', function () use ($plugin) {
            /** @var \MapasCulturais\Entities\Job $this */
            $configuration = $this->evaluationMethodConfiguration ?? null;
            $moderation = $plugin->fixedOpportunity()->isConfigured() ? $plugin->fixedOpportunity()->get()?->evaluationMethodConfiguration : null;

            if ($configuration && $moderation && $configuration->id === $moderation->id) {
                (new Services\ModerationQueue($plugin->fixedOpportunity()))->releasePhase($configuration);
            }
        });

        // As opções da fila, logo depois das comissões, só na fase de moderação do programa e com a distribuição
        // automática. Por gancho do componente da fase: o Cultura Viva sobrescreve o template das comissões.
        $app->hook('component(opportunity-phase-config-evaluation).documentary-config:before', function () use ($plugin) {
            $moderation = Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() ? $plugin->fixedOpportunity()->get()?->evaluationMethodConfiguration : null;

            if ($moderation) {
                $this->import('mdc-moderation-queue-config');
                echo "<section v-if=\"phase.id == {$moderation->id} && phase.distributionConfiguration != 'deactivate'\" class=\"evaluation-section col-12\"><mdc-moderation-queue-config :entity=\"phase\"></mdc-moderation-queue-config></section>";
            }
        });
    }

    // Ver Services\EvaluationSendGuard. Só no programa: no servidor, o status é lido no banco antes de cada gravação do
    // parecer, do formulário e do envio em lote; na tela, os botões esperam a ação anterior terminar.
    private function sendEachEvaluationOnce(): void
    {
        $app = App::i();
        $plugin = $this;
        $guard = new Services\EvaluationSendGuard;

        $app->hook('entity(RegistrationEvaluation).save:before', function () use ($plugin, $guard) {
            /** @var \MapasCulturais\Entities\RegistrationEvaluation $this */
            if ($this->id && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->includes($this->registration)) {
                $guard->check($this);
            }
        });

        // Pelo formulário (Salvar, Concluir), o parecer já enviado não muda: ver EvaluationSendGuard::isSentBy. Um `uid`
        // que não existe vale como o usuário logado, como no core.
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

        // Na tela, uma ação por vez nos botões da avaliação (evaluation-submit-guard.js), como no Cultura Viva: junto do
        // script do componente, em toda tela que o usa no subsite.
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'evaluation-actions' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-evaluation-submit-guard', 'js/evaluation-submit-guard.js', ['evaluation-actions']);
            }
        }, 1000);
    }

    // Moderação (ver Services\Moderation): "não ok" devolve o cadastro ao mestre em vez de recusá-lo.
    private function returnRefusedRegistrations(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->config['mailer.templates'][Services\Moderation::MAIL_RETURNED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro está pendente'),
            'template' => Services\Moderation::MAIL_RETURNED . '.html',
        ];

        // O hook do resultado não recebe a inscrição: é a da avaliação que está sendo enviada. Guardada antes de o
        // core aplicar o resultado, no mesmo `send:after` e com prioridade menor; o `send:before` do core sai
        // com o nome quebrado (`hookClassName`, que a entidade não tem).
        $sending = null;

        $app->hook('entity(RegistrationEvaluation).send:after', function () use (&$sending) {
            $sending = $this;
        }, 1);

        // Com a autoaplicação, a avaliação documental recusada tornaria o cadastro inválido; ele segue pendente.
        $app->hook('evaluationMethod(documentary).consolidatedAutoApplicationResult', function (&$result) use (&$sending, $plugin) {
            if ($result === Registration::STATUS_INVALID && $sending && $plugin->fixedOpportunity()->includes($sending->registration)) {
                $result = Registration::STATUS_SENT;
                // O status não muda de fato: sem isto, o core copiaria a inscrição para a fase seguinte à toa.
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

    // O mestre reenviou a correção dos campos devolvidos: a avaliação volta a quem avaliou, e ele recebe a confirmação
    // (ver Services\Moderation).
    private function reopenEvaluationOnResend(): void
    {
        $app = App::i();
        $plugin = $this;

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

    // Na correção do cadastro devolvido, o core cobra todos os obrigatórios, mas a mestra só edita os campos reabertos:
    // um obrigatório criado depois do envio ficaria vazio e travado, e ela nunca reenviaria. Dos erros de campo do
    // formulário (`field_N`; o anexo, `file_N`, está reaberto como `rfc_N`), ficam só os dos reabertos; os demais
    // erros seguem. Nos dois grupos do core: o dos metadados (validationErrors) e o do envio (sendValidationErrors).
    // Para cobrar o campo novo, a moderação o recusa na avaliação e ele é reaberto na devolução. No primeiro envio,
    // vale a regra do core.
    private function validateOnlyTheReopenedFields(): void
    {
        $plugin = $this;

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

        // Depois das validações do plugin que acrescentam erros de campo no envio (validateActingFieldOnSend,
        // validatePrizeIdOnSend): sem a prioridade, valeria a ordem das linhas do _init.
        App::i()->hook('entity(Registration).<<validationErrors|sendValidationErrors>>', $onlyReopened, 100);
    }

    // Resultado da moderação para o mestre, acima das abas da inscrição: em análise, com o que acontece depois;
    // devolvido (tela 8 do protótipo), com o resultado de cada campo, o motivo dos recusados e o caminho para
    // corrigir; ou aprovado, com o certificado. O
    // `tabs-alert` é o espaço de aviso do `mc-tabs`; decidido aqui, no servidor, porque a `Entity` do front só guarda
    // as propriedades que conhece.
    private function showModerationToOwner(): void
    {
        $app = App::i();
        $plugin = $this;

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

            // Em análise: o aviso do que acontece depois e, no selo do topo da ficha (registration/single.php), que diz
            // "Enviada" para qualquer status enviado, "Em análise". O gancho vem antes do selo.
            if (!$moderation && Services\Moderation::underReview($registration->status, $registration->editableFields, $registration->editSentTimestamp)) {
                Services\ProgramVocabulary::apply(['Enviada' => i::__('Em análise')]);
                $moderation = ['status' => 'review'];
            }

            if (!$moderation && $registration->status === Registration::STATUS_APPROVED && ($seal = $plugin->programSeal()->relationOf($registration))) {
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

    // Selo do programa (ver Services\ProgramSeal): entra na aprovação, antes do aviso que leva o link do
    // certificado, e sai se o cadastro deixa de estar aprovado. Só na fase de cadastro, como o aviso. Os dados públicos
    // da busca (Services\MasterPublicData) entram e saem junto.
    private function applyProgramSeal(): void
    {
        $app = App::i();
        $plugin = $this;

        $isRegistrationPhase = fn (Registration $registration): bool => $registration->opportunity->id === $plugin->fixedOpportunity()->get()?->id;

        // A aprovação, nesta ordem e num gancho só: o selo, os dados públicos e o aviso, que leva o link do certificado
        // (o próprio selo). Só na fase de cadastro: a cópia para a fase final também passa por aqui e avisaria duas vezes.
        $app->hook('entity(Registration).status(approved)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->apply($this);
                (new Services\MasterPublicData)->publish($this);
                (new Services\Moderation)->notifyApproved($this, $plugin->programSeal()->relationOf($this)?->singleUrl);
            }
        });

        $app->hook('entity(Registration).status(<<draft|sent|invalid|notapproved|waitlist>>)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->remove($this);
                (new Services\MasterPublicData)->clear($this->owner);
            }
        });

        // Com o selo retirado, o link do certificado (o do e-mail de aprovação) daria erro 500: o core usa o vínculo
        // antes de ver se ele existe. Aqui, vira "não encontrado".
        $app->hook('GET(seal.sealRelation):before', function () use ($app) {
            if (Plugin::isTheme() && !$app->repo('SealRelation')->find((int) ($this->data['id'] ?? 0))) {
                $app->pass();
            }
        });

        // A página do certificado é a do core, sem botão de impressão e com um "Voltar" que aponta para `#`: a barra
        // do plugin entra logo depois do cabeçalho do site, só no certificado do selo do programa e no dos festejos.
        $app->hook('template(seal.sealRelation.main-header):after', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $relation = $app->repo('SealRelation')->find((int) ($this->controller->data['id'] ?? 0));

            if ($relation && ($plugin->programSeal()->isTheProgramSeal($relation->seal) || $plugin->festivitySeal()->isTheFestivitySeal($relation->seal))) {
                $this->import('mdc-certificate-actions');
                echo '<mdc-certificate-actions></mdc-certificate-actions>';
            }
        });
    }

    // A idade calculada da data de nascimento, abaixo do campo do formulário que grava nela (ver o componente mdc-age).
    private function showAgeInForm(): void
    {
        $app = App::i();

        $app->hook('template(<<*>>.<<*>>.entity-field):end', function () use ($app) {
            if (Plugin::isTheme()) {
                $this->import('mdc-age');
                echo '<mdc-age v-if="description?.registrationFieldConfiguration?.config?.entityField === \'dataDeNascimento\'" :value="entity[prop]"></mdc-age>';
            }
        });
    }

    // As seções do formulário ganham o nome delas como marca, para o tema pôr o ícone de cada uma (ver o componente
    // mdc-section-marks). Só no subsite: nos outros, o formulário segue como no core.
    private function markFormSections(): void
    {
        $app = App::i();

        $app->hook('component(registration-form):end', function () use ($app) {
            if (Plugin::isTheme()) {
                $this->import('mdc-section-marks');
                echo '<mdc-section-marks></mdc-section-marks>';
            }
        });
    }


    // O e-mail de aprovação; ele sai no gancho da aprovação (applyProgramSeal), depois do selo.
    private function notifyApproval(): void
    {
        App::i()->config['mailer.templates'][Services\Moderation::MAIL_APPROVED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro foi aprovado'),
            'template' => Services\Moderation::MAIL_APPROVED . '.html',
        ];
    }

    // O `entity-field` não desenha tipos que não conhece; o componente devolve o valor como evento de input
    // para o salvamento automático do core seguir valendo. Vai em toda página com `entity-field`: o Vue
    // resolve o nome do componente mesmo dentro de `v-if` falso. O rótulo do core chega pelo id (o `<label for>` do
    // campo do festejo) ou pelo texto (o grupo da Atuação), como o core repassa o id ao entity-field-datepicker.
    private function renderActingField(): void
    {
        App::i()->hook('template(<<*>>.<<*>>.entity-field-label):after', function () {
            $this->import('mdc-acting-field mdc-festivity-field');

            $slug = Services\ActingField::SLUG;
            $festivity = Services\FestivityField::SLUG;

            // O festejo do Calendário (item 24.4 da V3) entra do mesmo jeito: componente próprio dentro do campo do core.
            echo <<<HTML
                <mdc-acting-field v-if="is('{$slug}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    :field-label="label || description.label" @change="change({ target: { value: \$event } }, true)"></mdc-acting-field>
                <mdc-festivity-field v-if="is('{$festivity}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    :field-id="propId" @change="change({ target: { value: \$event } }, true)"></mdc-festivity-field>
            HTML;
        });
    }

    // No envio o core só roda as validações `v::` do tipo; a da árvore entra por aqui. Também recusa o
    // rascunho que tem item tirado da lista depois da última gravação: a pessoa recarrega e vê o que saiu.
    private function validateActingFieldOnSend(): void
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

    // Premiado pelo MinC: o ID do painel ou a marca de que não o localizou (ver Services\PrizeId).
    private function validatePrizeIdOnSend(): void
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
    private function freezeActingFieldOnSend(): void
    {
        App::i()->hook('entity(Registration).send:before', function () {
            /** @var Registration $this */
            $this->registerFieldsMetadata();

            foreach (Services\ActingField::fieldsOf($this) as $field) {
                $this->{$field->fieldName} = $this->{$field->fieldName};
            }
        });
    }

    // A ficha do v1 não sabe exibir alguns campos do formulário; nas telas de leitura, eles já vão montados
    // em HTML, com o CSS e o JS do plugin.
    private function showFieldsOnV1Reading(): void
    {
        $app = App::i();
        $plugin = $this;

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

            // A atuação, que o v1 mostraria como texto cru, vai em cartões, a partir do espelho gravado.
            foreach (Services\ActingField::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\ActingField::toHtml($result[$field->fieldName]);
                }
            }

            // Paliativo para fotos e vídeos do agente (ver AgentMediaView), só na oportunidade do programa.
            if (!$plugin->fixedOpportunity()->includes($this)) {
                return;
            }

            foreach (Services\AgentMediaView::fieldsOf($this) as $field) {
                if (is_array($result[$field->fieldName] ?? null)) {
                    $result[$field->fieldName] = Services\AgentMediaView::toHtml($field, $result[$field->fieldName]);
                }
            }
        });
    }

    // A foto clicada na ficha do v1 abre aqui, na página de cima: o iframe da ficha cresce com o conteúdo.
    private function renderPhotoViewer(): void
    {
        App::i()->hook('template(<<*>>.<<*>>.main-header):after', function () {
            $this->import('mdc-photo-viewer');

            echo '<mdc-photo-viewer></mdc-photo-viewer>';
        });
    }

    // O PDF da ficha formata os campos pelo core, que não conhece o tipo; na rota dele, o campo é lido
    // como texto em trilhas, que o formatador já sabe quebrar em linhas.
    private function showActingFieldAsTextInPdf(): void
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

    // O limite do core conta por agente responsável, não por pessoa.
    private function limitToOneRegistrationPerUser(): void
    {
        App::i()->hook('entity(Registration).validations', function (&$validations) {
            $validations['owner']['\MDC\Plugin::isTheOnlyRegistrationOfTheUser($this)'] =
                i::__('Você já tem um cadastro nesta oportunidade.');
        });
    }

    static function isTheOnlyRegistrationOfTheUser(Registration $registration): bool
    {
        // Alterar uma inscrição existente não é criar uma segunda.
        if ($registration->id) {
            return true;
        }

        $app = App::i();
        $fixed = (new Services\FixedOpportunity)->get();

        if (!$fixed || $registration->opportunity?->id !== $fixed->id || !$registration->owner?->user) {
            return true;
        }

        // Integridade precisa da verdade do banco, não do que o usuário da requisição enxerga.
        $count = $app->em->createQuery(
            'SELECT COUNT(r.id) FROM MapasCulturais\Entities\Registration r
              JOIN r.owner a
             WHERE r.opportunity = :opportunity AND a.user = :user AND r.status >= 0'
        )
            ->setParameter('opportunity', $fixed)
            ->setParameter('user', $registration->owner->user)
            ->getSingleScalarResult();

        return !$count;
    }

    // Diz se a tela é do subsite dos Mestres, onde valem as regras do programa.
    static function isTheme(): bool
    {
        return App::i()->view instanceof \MestresDaCultura\Theme;
    }

    // Quem não é admin no subsite dos Mestres: só as telas do painel do programa abrem (PanelMenu::closeOtherPanelScreens)
    // e só o selo do programa aparece. O que é de outro subsite (inscrições, oportunidades, selos, notificações) fica de
    // fora para todos, com Plugin::isTheme; as travas de estrutura seguem outra regra: só o saasSuperAdmin passa (ver
    // Services\FixedOpportunityGuard).
    // No subsite dos Mestres todo evento é festejo (ver Hooks\Festivities::openTheFestivityForm); o de outro subsite,
    // aberto daqui, não.
    static function isFestivity(Event $event): bool
    {
        return self::isTheme() && (!$event->id || Services\SubsiteRedirect::isCurrent($event->subsite));
    }

    static function appliesToCurrentUser(): bool
    {
        return self::isTheme() && !App::i()->user->is('admin');
    }

    function fixedOpportunity(): Services\FixedOpportunity
    {
        return $this->fixedOpportunity ??= new Services\FixedOpportunity();
    }

    function programSeal(): Services\ProgramSeal
    {
        return $this->programSeal ??= new Services\ProgramSeal();
    }

    function festivitySeal(): Services\FestivitySeal
    {
        return $this->festivitySeal ??= new Services\FestivitySeal();
    }

}
