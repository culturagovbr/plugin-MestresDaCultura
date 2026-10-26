<?php

namespace MDC;

use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Definitions\RegistrationFieldType;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
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

    /** Chave que marca, no arquivo exportado, a oportunidade do programa (ver completeOpportunityImport). */
    private const IMPORT_MARK = 'mdcOpportunity';

    private ?Services\FixedOpportunity $fixedOpportunity = null;

    private ?Services\ProgramSeal $programSeal = null;

    function register()
    {
        $app = App::i();

        // Ids da oportunidade e dos selos, do ambiente e sem padrão; valem também nos jobs da linha de comando.
        $app->config[Services\FixedOpportunity::CONFIG_KEY] ??= env('MDC_OPPORTUNITY_ID');
        $app->config[Services\ProgramSeal::CONFIG_KEY] ??= env('MDC_SEAL_ID');

        $app->registerController('mdc', Controller::class);
        $app->registerRegistrationFieldType(new RegistrationFieldType(Services\ActingField::definition()));
        $app->registerJobType(new Jobs\ReleaseStaleDrafts(Jobs\ReleaseStaleDrafts::SLUG));
        $this->registerModerationQueueOptions();
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

    // O campo do formulário grava no perfil do agente: sem a opção na definição do metadado, o perfil não
    // mostraria o que foi escolhido. Vale em todo o Mapa, porque o perfil é um só. Depois de todos os
    // registros, porque outros plugins (SNIICDataStandard) registram de novo a lista, uma por tipo de agente.
    private function addTraditionalCommunities(): void
    {
        $app = App::i();

        $app->hook('app.register:after', function () use ($app) {
            $definitions = [];

            foreach ([null, ...$app->getRegisteredEntityTypes(Agent::class)] as $type) {
                $definition = $app->getRegisteredMetadataByMetakey('comunidadesTradicional', Agent::class, $type?->id);

                if ($definition) {
                    $definitions[spl_object_id($definition)] = $definition;
                }
            }

            foreach ($definitions as $definition) {
                $definition->options = self::withTraditionalCommunities($definition->options);
            }
        });
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
        $this->registerTreeNodeObjectType();
        $this->renderActingField();
        $this->validateActingFieldOnSend();
        $this->validatePrizeIdOnSend();
        $this->freezeActingFieldOnSend();
        $this->showFieldsOnV1Reading();
        $this->renderPhotoViewer();
        $this->showActingFieldAsTextInPdf();
        $this->adjustRegistrationsSpreadsheet();
        $this->addTreeCurationPage();
        $this->addManagementShortcuts();
        $this->addModerationShortcut();
        $this->blockDisabledEntities();
        $this->blockOpportunityBrowsing();
        $this->protectFixedOpportunity();
        $this->lockFixedOpportunityStructure();
        $this->scopeRegistrationsToFixedOpportunity();
        $this->sendForeignRegistrationsHome();
        $this->keepTheProgramOnItsSite();
        $this->closeOtherPanelScreens();
        $this->showOnlyTheProgramSeal();
        $this->leaveOutOtherProgramsNotifications();
        $this->limitToOneRegistrationPerUser();
        $this->addTraditionalCommunities();
        $this->completeOpportunityImport();
        $this->sendEachEvaluationOnce();
        $this->passOnTheDisabledModeratorsWork();
        $this->adjustTheRegistrationsList();
        $this->showEvaluationSentDate();
        $this->showRoleBlocksInThePanel();
        $this->keepOnePlacePerModerator();
        $this->returnRefusedRegistrations();
        $this->reopenEvaluationOnResend();
        $this->showModerationToOwner();
        $this->applyProgramSeal();
        $this->showMasterProfile();
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

    // Ver Services\RegistrationsTable. Só na lista de cadastros do programa; nas outras oportunidades, como no core.
    private function adjustTheRegistrationsList(): void
    {
        $app = App::i();
        $plugin = $this;
        $isProgram = fn (?Opportunity $opportunity): bool => Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($opportunity);

        $app->hook('entity(Registration).propertiesMetadata', function (&$result) {
            if (Plugin::isTheme()) {
                $result[Services\RegistrationsTable::MODERATION] = Services\RegistrationsTable::property();
            }
        });

        $app->hook('component(opportunity-registrations-table).additionalHeaders', function (&$headers, &$select) use ($app, $isProgram) {
            if ($isProgram($app->view->controller->requestedEntity ?? null)) {
                Services\RegistrationsTable::adjust($headers, $select);
                $app->view->jsObject['config']['mdcRegistrationsTable'] = ['after' => Services\RegistrationsTable::PLACED_AFTER, 'hidden' => Services\RegistrationsTable::UNUSED];
            }
        });

        // A situação de cada linha da lista, como o Cultura Viva acrescenta a data de envio na lista de avaliações.
        $app->hook('ApiQuery(Registration).findResult', function (&$result) use ($app, $isProgram) {
            $request = $app->request;

            if (!$request || $request->controllerId !== 'opportunity' || $request->action !== 'findRegistrations') {
                return;
            }

            if ($isProgram($app->repo('Opportunity')->find((int) $request->get('@opportunity')))) {
                foreach ($result as &$row) {
                    if (is_array($row) && array_key_exists('status', $row)) {
                        $row[Services\RegistrationsTable::MODERATION] = Services\RegistrationsTable::label($row);
                    }
                }
                unset($row);
            }
        });

        // O padrão de colunas do programa (registrations-table.js), junto do script do componente.
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'opportunity-registrations-table' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-registrations-table', 'js/registrations-table.js', ['opportunity-registrations-table']);
            }
        }, 1000);
    }

    // Ver Services\PanelSummary. No ponto de gancho da view do painel do tema, antes de "Meus cadastros", como o aviso
    // da moderação entra no acompanhamento do cadastro.
    private function showRoleBlocksInThePanel(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->hook('template(panel.index.mdc-role-blocks):before', function () use ($app, $plugin) {
            $summary = (new Services\PanelSummary($plugin->fixedOpportunity()))->for($app->user);

            if (!$summary['moderation'] && !$summary['management']) {
                return;
            }

            $this->import('mdc-panel-summary');
            $json = htmlspecialchars(json_encode($summary), ENT_QUOTES);

            echo "<mdc-panel-summary :summary=\"{$json}\"></mdc-panel-summary>";
        });
    }

    // Ver Services\EvaluationsTable. Como no Cultura Viva: a data entra na consulta e sai formatada só na lista de
    // avaliações, aqui só na do programa.
    private function showEvaluationSentDate(): void
    {
        $app = App::i();
        $plugin = $this;
        $isProgramList = function () use ($app, $plugin): bool {
            $request = $app->request;

            return Plugin::isTheme() && $request && $request->controllerId === 'opportunity' && $request->action === 'findEvaluations'
                && $plugin->fixedOpportunity()->isConfigured()
                && $plugin->fixedOpportunity()->matches($app->repo('Opportunity')->find((int) $request->get('@opportunity')));
        };

        $app->hook('ApiQuery(RegistrationEvaluation).params', function (&$params) use ($isProgramList) {
            if (!empty($params['@select']) && !str_contains($params['@select'], 'sentTimestamp') && $isProgramList()) {
                $params['@select'] .= ',sentTimestamp';
            }
        });

        $app->hook('ApiQuery(RegistrationEvaluation).findResult', function (&$result) use ($isProgramList) {
            if ($isProgramList()) {
                Services\EvaluationsTable::format($result);
            }
        });

        $app->hook('component(opportunity-evaluations-table).additionalHeaders', function (&$headers) use ($app, $plugin) {
            $phase = $app->view->controller->requestedEntity ?? null;

            if (Plugin::isTheme() && $plugin->fixedOpportunity()->isConfigured() && $plugin->fixedOpportunity()->matches($phase)) {
                Services\EvaluationsTable::adjust($headers, $app->view->controller->action === 'userEvaluations');
                $app->view->jsObject['config']['mdcEvaluationsTable'] = ['opportunity' => $phase->id, 'visible' => [Services\EvaluationsTable::SLUG], 'tables' => Services\EvaluationsTable::TABLES, 'hidden' => Services\EvaluationsTable::UNUSED];
            }
        });

        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'opportunity-evaluations-table' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-evaluations-table', 'js/evaluations-table.js', ['opportunity-evaluations-table']);
            }
        }, 1000);
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

    // Ver Services\EvaluationSendLock. Só no programa: no servidor, a trava segura a linha da avaliação durante a
    // gravação; na tela, os botões esperam a ação anterior terminar.
    private function sendEachEvaluationOnce(): void
    {
        $app = App::i();
        $plugin = $this;
        $lock = new Services\EvaluationSendLock;

        $app->hook('entity(RegistrationEvaluation).save:before', function () use ($plugin, $lock) {
            /** @var \MapasCulturais\Entities\RegistrationEvaluation $this */
            if (Services\EvaluationSendLock::isSending($this) && $plugin->fixedOpportunity()->includes($this->registration)) {
                $lock->claim($this);
            }
        });

        $app->hook('entity(RegistrationEvaluation).save:finish', function () use ($lock) {
            $lock->release($this);
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

    // O mestre reenviou a correção dos campos devolvidos: a avaliação volta a quem avaliou (ver Services\Moderation).
    private function reopenEvaluationOnResend(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->config['mailer.templates'][Services\Moderation::MAIL_RESENT] = [
            'title' => i::__('[Rede de Mestras e Mestres] Cadastro corrigido para nova análise'),
            'template' => Services\Moderation::MAIL_RESENT . '.html',
        ];

        $app->hook('entity(Registration).sendEditableFields:after', function () use ($plugin) {
            /** @var Registration $this */
            if ($plugin->fixedOpportunity()->includes($this)) {
                (new Services\Moderation)->sendBackToValuers($this);
            }
        });
    }

    // Resultado da moderação para o mestre, acima das abas da inscrição: devolvido (tela 8 do protótipo), com o
    // resultado de cada campo, o motivo dos recusados e o caminho para corrigir; ou aprovado, com o certificado. O
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
    // certificado, e sai se o cadastro deixa de estar aprovado. Só na fase de cadastro, como o aviso.
    private function applyProgramSeal(): void
    {
        $app = App::i();
        $plugin = $this;

        $isRegistrationPhase = fn (Registration $registration): bool => $registration->opportunity->id === $plugin->fixedOpportunity()->get()?->id;

        $app->hook('entity(Registration).status(approved)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->apply($this);
            }
        });

        $app->hook('entity(Registration).status(<<draft|sent|invalid|notapproved|waitlist>>)', function () use ($plugin, $isRegistrationPhase) {
            /** @var Registration $this */
            if ($isRegistrationPhase($this)) {
                $plugin->programSeal()->remove($this);
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
        // do plugin entra logo depois do cabeçalho do site, só no certificado do selo do programa.
        $app->hook('template(seal.sealRelation.main-header):after', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $relation = $app->repo('SealRelation')->find((int) ($this->controller->data['id'] ?? 0));

            if ($relation && $plugin->programSeal()->isTheProgramSeal($relation->seal)) {
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

    // Perfil público do mestre aprovado (tela 9 do protótipo) sobre a página de agente do core, só por ganchos: o
    // cabeçalho ganha o nome artístico, a atuação, a cidade e desde quando integra a Rede; a coluna lateral, o
    // certificado; a principal, a atuação cultural. Ver Services\MasterProfile.
    private function showMasterProfile(): void
    {
        $app = App::i();
        $plugin = $this;
        $profile = false;

        $render = function (string $part) use ($app, $plugin, &$profile) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $agent = $this->controller->requestedEntity;

            if ($profile === false) {
                $profile = $agent instanceof \MapasCulturais\Entities\Agent
                    ? (new Services\MasterProfile($plugin->fixedOpportunity(), $plugin->programSeal()))->of($agent)
                    : null;
            }

            if (!$profile) {
                return;
            }

            $this->import('mdc-master-profile');
            $json = htmlspecialchars(json_encode($profile), ENT_QUOTES);

            echo "<mdc-master-profile part=\"{$part}\" :profile=\"{$json}\"></mdc-master-profile>";
        };

        $parts = [
            'entity-header):end' => 'header',
            'single1-entity-info-taxonomie-area):before' => 'certificate',
            'entity-gallery-video):before' => 'acting',
        ];

        foreach ($parts as $hook => $part) {
            $app->hook("template(agent.single.{$hook}", function () use ($render, $part) {
                $render->call($this, $part);
            });
        }
    }


    private function notifyApproval(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->config['mailer.templates'][Services\Moderation::MAIL_APPROVED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro foi aprovado'),
            'template' => Services\Moderation::MAIL_APPROVED . '.html',
        ];

        // Só na fase de cadastro: a cópia para a fase final também passa por aqui e avisaria duas vezes.
        $app->hook('entity(Registration).status(approved)', function () use ($plugin) {
            /** @var Registration $this */
            if ($this->opportunity->id === $plugin->fixedOpportunity()->get()?->id) {
                (new Services\Moderation)->notifyApproved($this, $plugin->programSeal()->relationOf($this)?->singleUrl);
            }
        });
    }

    // A oportunidade do programa sobe por importação, já configurada e com o resultado publicado. O importador do
    // core descarta parte do que o exportador grava (a autoaplicação de resultados e os campos que o avaliador vê)
    // e o exportador não leva o fluxo contínuo. Só a exportação da oportunidade fixa leva a marca, e só o arquivo
    // com ela é completado: as outras importações seguem como no core. A comissão fica de fora: são pessoas de
    // cada ambiente, e o gestor a monta depois de importar.
    private function completeOpportunityImport(): void
    {
        $app = App::i();
        $plugin = $this;

        // A marca vai na fase de avaliação, a única parte do arquivo que os hooks do exportador alcançam.
        $app->hook('evaluationMethod(<<*>>).export:after', function (&$result) use ($plugin) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfiguration $this */
            if ($plugin->fixedOpportunity()->matches($this->opportunity)) {
                $result[self::IMPORT_MARK] = [
                    'isContinuousFlow' => (bool) $this->opportunity->firstPhase->isContinuousFlow,
                    'hasEndDate' => (bool) $this->opportunity->firstPhase->hasEndDate,
                ];
            }
        });

        // Chega com os campos já trocados pelos da oportunidade nova; o importador salva a fase em seguida.
        $app->hook('evaluationMethod(<<*>>).import:after', function (&$data) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfiguration $this */
            $mark = $data[self::IMPORT_MARK] ?? null;

            if (!$mark) {
                return;
            }

            foreach (['autoApplicationAllowed', 'publishEvaluationDetails', 'publishValuerNames', 'infos'] as $prop) {
                if (array_key_exists($prop, $data)) {
                    $this->$prop = $data[$prop];
                }
            }

            $opportunity = $this->opportunity;
            $opportunity->avaliableEvaluationFields = $data['avaliableEvaluationFields'] ?? [];
            $opportunity->save(true);

            // A fase final já existe a esta altura; o core só copia o fluxo contínuo para fases criadas depois.
            foreach ($opportunity->allPhases as $phase) {
                $phase->isContinuousFlow = $mark['isContinuousFlow'];
                $phase->hasEndDate = $mark['hasEndDate'];
                $phase->save(true);
            }

            // O core só mostra o resultado ao inscrito depois da publicação, pensada para sair de uma vez no fim de
            // um edital. No cadastro cada mestre é avaliado num momento: publicada desde o início, a oportunidade
            // mostra cada resultado assim que é aplicado, como no Cultura Viva. Nasce sem inscrições, então
            // publicar não aplica selo nem avisa ninguém.
            $opportunity->publishRegistrations();
        });
    }

    // `object_type` é enum do Postgres e também lista fixa em PHP; o db-update cobre uma ponta, este hook a outra.
    private function registerTreeNodeObjectType(): void
    {
        App::i()->hook('doctrine.emum(object_type).values', function (&$values) {
            $values['TreeNode'] = Entities\Node::class;
        });
    }

    // O `entity-field` não desenha tipos que não conhece; o componente devolve o valor como evento de input
    // para o salvamento automático do core seguir valendo. Vai em toda página com `entity-field`: o Vue
    // resolve o nome do componente mesmo dentro de `v-if` falso.
    private function renderActingField(): void
    {
        App::i()->hook('template(<<*>>.<<*>>.entity-field-label):after', function () {
            $this->import('mdc-acting-field');

            $slug = Services\ActingField::SLUG;

            echo <<<HTML
                <mdc-acting-field v-if="is('{$slug}')" :entity="entity" :prop="prop" :disabled="readonly || disabled"
                    @change="change({ target: { value: \$event } }, true)"></mdc-acting-field>
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
            }
        });
    }

    // A planilha de inscrições não conhece a atuação e escreve mal fotos, vídeos e seções; os ajustes
    // estão em Services\RegistrationsSpreadsheet.
    private function adjustRegistrationsSpreadsheet(): void
    {
        $app = App::i();
        $spreadsheet = new Services\RegistrationsSpreadsheet($this->fixedOpportunity());
        $job = 'SpreadsheetJob(' . Services\RegistrationsSpreadsheet::JOB . ')';

        $app->hook("{$job}.getHeader:after", function ($job, &$header) use ($spreadsheet) {
            $spreadsheet->removeEmptyColumns($job->owner, $header);
            $spreadsheet->addAgeColumn($job->owner, $header);
        });

        $app->hook("{$job}.getBatch:before", function ($job, &$query) use ($spreadsheet) {
            $spreadsheet->startBatch($job->owner, $query);
        });

        $app->hook('entity(Opportunity).registrationMetadata', function () use ($spreadsheet) {
            /** @var Opportunity $this */
            $spreadsheet->readAsCells($this);
        });

        $app->hook("{$job}.getBatch:after", function ($job, &$rows) use ($spreadsheet) {
            $spreadsheet->fillAge($rows);
            $spreadsheet->finishBatch();
        });
    }

    // Curadoria da árvore: página no painel e item no grupo de administração, só para administradores e
    // só no subsite do programa.
    private function addTreeCurationPage(): void
    {
        $app = App::i();

        $app->hook('GET(panel.mdc-tree-curation)', function () use ($app) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!Plugin::isTheme()) {
                $app->pass();
            }

            if (!Services\Curators::includes()) {
                $app->halt(403, i::__('Acesso restrito.'));
            }

            $app->view->enqueueScript('app-v2', 'mdc-tree-curation', 'js/tree-curation.js');

            $this->render('mdc-tree-curation');
        });

        $app->hook('panel.nav', function (&$nav) use ($app) {
            if (!Plugin::isTheme() || !isset($nav['admin'])) {
                return;
            }

            $nav['admin']['items'][] = [
                'route' => 'panel/mdc-tree-curation',
                'icon' => 'mdc-tree',
                'label' => i::__('Curadoria da Árvore de Atuação'),
                'condition' => fn () => Services\Curators::includes(),
            ];
        });

        $app->hook('component(mc-icon).iconset', function (&$iconset) {
            $iconset['mdc-tree'] = 'mdi:file-tree-outline';
            $iconset['mdc-zoom-in'] = 'mdi:plus';
            $iconset['mdc-zoom-out'] = 'mdi:minus';
            $iconset['mdc-not-proven'] = 'mdi:close-circle';
            $iconset['mdc-certificate'] = 'mdi:certificate';
        });
    }

    // Quem controla a oportunidade do programa (o gestor do subsite ou quem ela tem como administrador) ganha no menu
    // do painel atalhos para os cadastros, as avaliações e a comissão. Depois do menu do tema (prioridade 1000).
    private function addManagementShortcuts(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();
        $controls = fn (): bool => $fixedOpportunity->isConfigured() && (bool) $fixedOpportunity->get()?->canUser('@control');

        $app->hook('panel.nav', function (&$nav) use ($controls, $fixedOpportunity) {
            if (!Plugin::isTheme() || !$controls()) {
                return;
            }

            $group = ['mdc-gestao' => ['label' => i::__('Gestão do cadastro'), 'column' => 'left', 'items' => Services\PanelSummary::shortcuts($fixedOpportunity->get())]];
            $position = array_search('mdc-criar', array_keys($nav), true);
            $nav = $position === false ? $group + $nav : array_slice($nav, 0, $position + 1, true) + $group + $nav;
        }, 1001);

        // O menu do core não passa âncora ao link: a comissão fica na aba de fases da edição.
        $app->hook('GET(panel.mdc-committee)', function () use ($app, $controls, $fixedOpportunity) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!Plugin::isTheme() || !$controls()) {
                $app->pass();
            }

            $app->redirect($app->createUrl('opportunity', 'edit', [$fixedOpportunity->get()->id]) . '#config');
        });
    }

    // "Minhas avaliações" do core lista as avaliações da instalação inteira. No subsite dos Mestres, o moderador ativo
    // vai direto à lista da moderação e quem não é não vê o item; o saasSuperAdmin mantém o menu do core.
    private function addModerationShortcut(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->hook('panel.nav', function (&$nav) use ($app, $fixedOpportunity) {
            if (!Plugin::isTheme() || $app->user->is('saasSuperAdmin')) {
                return;
            }

            $phase = $fixedOpportunity->isConfigured() ? $fixedOpportunity->moderatedBy($app->user) : null;
            $moderation = $phase ? ['route' => 'opportunity/userEvaluations', 'params' => [$phase->id], 'icon' => 'opportunity', 'label' => i::__('Moderação de cadastros')] : null;

            foreach ($nav as &$group) {
                if (isset($group['items'])) {
                    $items = array_map(fn ($item) => ($item['route'] ?? '') === 'panel/evaluations' ? $moderation : $item, $group['items']);
                    $group['items'] = array_values(array_filter($items));
                }
            }
            unset($group);
        }, 1001);
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

    // Quem não é admin no subsite dos Mestres: só as telas do painel do programa abrem (closeOtherPanelScreens) e só o
    // selo do programa aparece. O que é de outro subsite (inscrições, oportunidades, selos, notificações) fica de fora
    // para todos, com Plugin::isTheme; as travas de estrutura seguem outra regra: só o saasSuperAdmin passa (ver
    // Services\FixedOpportunityGuard).
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

    private function blockOpportunityBrowsing(): void
    {
        $app = \MapasCulturais\App::i();

        // O plugin roda na instalação inteira; a oportunidade do programa nasce em outro subsite. Só a oportunidade
        // nova: o metadado novo pede "create" à oportunidade já gravada.
        $app->hook('entity(Opportunity).canUser(create)', function ($user, &$result) {
            /** @var Opportunity $this */
            if (!$this->id && Plugin::isTheme() && !$user->is('saasSuperAdmin')) {
                $result = false;
            }
        });

        // O tema roda sobre uma oportunidade fixa: não há o que listar. Só o saasSuperAdmin, que configura a do programa,
        // lista; o gestor do subsite chega a ela pela Gestão do cadastro (ver addManagementShortcuts).
        $app->hook('GET(<<search|panel>>.opportunities):before', function () use ($app) {
            if (Plugin::isTheme() && !$app->user->is('saasSuperAdmin')) {
                $app->pass();
            }
        });

        // Esconder o link não fecha a porta: sem isto, qualquer oportunidade da instalação
        // abre por URL direta. Alcança toda ação do controlador, e não só a página. Vale também para o gestor do
        // subsite; o saasSuperAdmin, que configura o programa, abre as oportunidades do subsite dos Mestres.
        $app->hook('ALL(opportunity.<<*>>):before', function () use ($app) {
            if (!Plugin::isTheme()) {
                return;
            }

            $requested = $this->urlData['id'] ?? null;

            if (!$requested) {
                return;
            }

            $opportunity = $app->repo('Opportunity')->find((int) $requested);

            if ($app->user->is('saasSuperAdmin')) {
                if ($opportunity && $opportunity->subsite?->id !== (new Services\FixedOpportunity)->subsiteId()) {
                    $app->pass();
                }

                return;
            }

            $fixed = (new Services\FixedOpportunity)->get();

            // A fase é filha da raiz e precisa passar junto: é ela que publica o resultado.
            $root = $opportunity?->parent ?: $opportunity;

            if (!$fixed || !$root || $root->id !== $fixed->id) {
                $app->pass();
            }
        });
    }

    // A estrutura da oportunidade do programa só muda pelo saasSuperAdmin (ver Services\FixedOpportunityGuard).
    private function protectFixedOpportunity(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();
        $guard = new Services\FixedOpportunityGuard($fixedOpportunity);

        $app->hook('entity(Opportunity).canUser(<<archive|remove|destroy|unpublish>>)', function ($user, &$result) use ($guard) {
            /** @var Opportunity $this */
            if ($guard->locks($this, $user)) {
                $result = false;
            }
        });

        // A fase de moderação fica na raiz; excluí-la é remover a configuração de avaliação.
        $app->hook('entity(EvaluationMethodConfiguration).canUser(<<remove|destroy>>)', function ($user, &$result) use ($guard) {
            if ($guard->locks($this->opportunity, $user)) {
                $result = false;
            }
        });

        // Rascunho e desativada não pedem permissão no core e tiram a oportunidade do ar como arquivar. Só a raiz:
        // as fases nascem fora do ar.
        $app->hook('entity(Opportunity).setStatus(<<*>>)', function ($status) use ($app, $guard, $fixedOpportunity) {
            /** @var Opportunity $this */
            $isRoot = $this->id && $this->id === $fixedOpportunity->get()?->id;

            if ($status <= 0 && $this->status > 0 && $isRoot && $app->isAccessControlEnabled() && $guard->locks($this, $app->user)) {
                throw new PermissionDenied($app->user, $this, 'unpublish');
            }
        });

        // Clonar a oportunidade só pede "modify" ou "@control" no core, e o gerar modelo do gestor quebrava no meio.
        // Tornar modelo público abriria o clone a qualquer usuário. Sem login, segue para o core, que pede para entrar.
        $app->hook('ALL(opportunity.<<duplicate|generatemodel|generateopportunity|modelpublic>>):before', function () use ($app, $guard) {
            if (!$app->user->is('guest') && $guard->locks($this->requestedEntity, $app->user)) {
                throw new PermissionDenied($app->user, $this->requestedEntity, $this->action);
            }
        });

        // Os dois botões também só pedem "modify": a classe deixa o tema escondê-los (_entity-actions.scss), e também
        // o que lockFixedOpportunityStructure recusa na configuração de fases e do formulário.
        $app->hook('GET(opportunity.<<edit|single|formbuilder>>):before', function () use ($app, $guard) {
            if ($guard->locks($this->requestedEntity, $app->user)) {
                $app->view->bodyClasses[] = 'mdc-structure-locked';
            }
        });
    }

    // Fases, datas e formulário da oportunidade do programa vêm da importação e só o saasSuperAdmin os altera. Na
    // comissão, o gestor inclui e desabilita moderadores; excluir apaga as avaliações do moderador e reabrir as
    // devolve a ele. Importação e jobs gravam com o controle de acesso desligado e não passam por aqui.
    private function lockFixedOpportunityStructure(): void
    {
        $app = App::i();
        $guard = new Services\FixedOpportunityGuard($this->fixedOpportunity());

        // Fase nova é oportunidade com o programa de pai, ou configuração de avaliação; excluir já é recusado em
        // protectFixedOpportunity. Só a entidade nova: o metadado novo pede "create" à dona, já gravada, e o gestor
        // segue gravando os dele (como a distribuição da comissão).
        $app->hook('entity(<<Opportunity|EvaluationMethodConfiguration>>).canUser(create)', function ($user, &$result) use ($guard) {
            $opportunity = $this instanceof Opportunity ? $this : $this->opportunity;

            if (!$this->id && $guard->locks($opportunity, $user)) {
                $result = false;
            }
        });

        // Campos e anexos pedem "modifyRegistrationFields" à fase dona.
        $app->hook('entity(Opportunity).canUser(modifyRegistrationFields)', function ($user, &$result) use ($guard) {
            /** @var Opportunity $this */
            if ($guard->locks($this, $user)) {
                $result = false;
            }
        });

        $app->hook('entity(RegistrationStep).canUser(<<create|modify|remove|destroy>>)', function ($user, &$result) use ($guard) {
            if ($guard->locks($this->opportunity, $user)) {
                $result = false;
            }
        });

        $app->hook('entity(EvaluationMethodConfigurationAgentRelation).canUser(<<remove|destroy>>)', function ($user, &$result) use ($guard) {
            if ($guard->locks($this->owner->opportunity, $user)) {
                $result = false;
            }
        });

        $app->hook('entity(EvaluationMethodConfigurationAgentRelation).reopen:before', function () use ($app, $guard) {
            if ($app->isAccessControlEnabled() && $guard->locks($this->owner->opportunity, $app->user)) {
                throw new PermissionDenied($app->user, $this, 'reopen');
            }
        });

        // Reabrir pela oportunidade e importar campos não passam por uma permissão que dê para recusar. Cada uma lê o
        // id de um lugar, como o core.
        $app->hook('POST(opportunity.<<importfields|reopenevaluations>>):before', function () use ($app, $guard) {
            $id = strtolower($this->action) === 'importfields' ? ($this->urlData['id'] ?? 0) : ($this->data['opportunityId'] ?? 0);
            $opportunity = $app->repo('Opportunity')->find((int) $id);

            if ($guard->locks($opportunity, $app->user)) {
                throw new PermissionDenied($app->user, $opportunity, $this->action);
            }
        });

        // A tela salva a fase inteira: a recusa é por data, comparada com o que está gravado. O erro de validação dá a
        // mensagem na tela; a recusa ao salvar vale para o Force-Save, que grava mesmo com erro.
        $lockedDates = function (Opportunity|EvaluationMethodConfiguration $entity) use ($app, $guard): array {
            $opportunity = $entity instanceof Opportunity ? $entity : $entity->opportunity;

            if (!$app->isAccessControlEnabled() || !$guard->locks($opportunity, $app->user)) {
                return [];
            }

            return $guard->changedDates($entity);
        };

        $message = i::__('Só a administração da plataforma altera as datas do programa.');

        $app->hook('entity(<<Opportunity|EvaluationMethodConfiguration>>).validationErrors', function (&$errors) use ($lockedDates, $message) {
            foreach ($lockedDates($this) as $property) {
                $errors[$property][] = $message;
            }
        });

        $app->hook('entity(<<Opportunity|EvaluationMethodConfiguration>>).save:before', function () use ($app, $lockedDates, $message) {
            if ($lockedDates($this)) {
                throw new PermissionDenied($app->user, $this, 'modify', $message);
            }
        });
    }

    // Ver Services\RegistrationScope. Para todos no subsite dos Mestres, inclusive o gestor e o saasSuperAdmin: o core
    // não tem filtro de inscrições por subsite (o de oportunidades, na configuração do subsite, não alcança a inscrição).
    private function scopeRegistrationsToFixedOpportunity(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->hook('ApiQuery(Registration).params', function (&$params) use ($app, $fixedOpportunity) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || !Plugin::isTheme()) {
                return;
            }

            // Sem oportunidade configurada, segue o core, sem registrar o aviso a cada consulta.
            if (!$fixedOpportunity->isConfigured()) {
                return;
            }

            $allowed = $fixedOpportunity->ids();

            if ($allowed) {
                $params['opportunity'] = API::IN(Services\RegistrationScope::narrow($params['opportunity'] ?? null, $allowed));
            }
        });
    }

    // No subsite dos Mestres, a inscrição de outro edital abre no subsite daquele edital (ver Services\SubsiteRedirect).
    // Se o site dela é este mesmo, não há para onde mandar: não abre.
    private function sendForeignRegistrationsHome(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->hook('GET(registration.<<*>>):before', function () use ($app, $fixedOpportunity) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            if (!Plugin::isTheme() || !$fixedOpportunity->isConfigured()) {
                return;
            }

            $registration = $this->requestedEntity;

            if (!$registration instanceof Registration || $fixedOpportunity->includes($registration)) {
                return;
            }

            $origin = $registration->opportunity?->subsite;

            if (!$registration->opportunity || Services\SubsiteRedirect::isCurrent($origin)) {
                $app->pass();
            }

            $app->redirect(Services\SubsiteRedirect::urlOn($origin, $this));
        });
    }

    // Fora do subsite dos Mestres, a oportunidade do programa sai da busca e da home, e as páginas dela e das inscrições
    // dela vão para o subsite dos Mestres, como o Cultura Viva faz com as dele. A busca só é filtrada quando pedida dessas
    // duas páginas: painéis, listas de avaliação e jobs seguem como no core.
    private function keepTheProgramOnItsSite(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();
        $elsewhere = fn (): bool => $fixedOpportunity->isConfigured()
            && ($fixed = $fixedOpportunity->get())
            && !Services\SubsiteRedirect::isCurrent($fixed->subsite);

        $app->hook('ApiQuery(Opportunity).params', function (&$params) use ($app, $fixedOpportunity, $elsewhere) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || !$app->request?->isAjax() || !$elsewhere()) {
                return;
            }

            $referer = $app->request->getReferer();
            $referer = strtok(is_array($referer) ? ($referer[0] ?? '') : (string) $referer, '?#');

            if (!in_array($referer, [$app->createUrl('site', 'index'), $app->createUrl('search', 'opportunities')], true)) {
                return;
            }

            $hidden = API::NOT_IN($fixedOpportunity->ids());
            $params['id'] = isset($params['id']) ? API::AND($params['id'], $hidden) : $hidden;
        });

        $app->hook('GET(<<opportunity|registration>>.<<*>>):before', function () use ($app, $fixedOpportunity, $elsewhere) {
            $entity = $this->requestedEntity;
            $opportunity = $entity instanceof Registration ? $entity->opportunity : $entity;

            if (!$opportunity instanceof Opportunity || !$fixedOpportunity->matches($opportunity) || !$elsewhere()) {
                return;
            }

            $app->redirect(Services\SubsiteRedirect::urlOn($fixedOpportunity->get()->subsite, $this));
        });
    }

    // O menu do subsite dos Mestres só mostra as telas do programa, mas as do Mapa genérico (eventos, aplicativos,
    // validações, prestação de contas) abriam por URL. Para quem não é admin, só estas abrem; a curadoria confere
    // sozinha quem é curador. Aplicativos e validações não são usados no subsite: não abrem para ninguém, nem a gravação
    // de aplicativo (o controlador `app`).
    private function closeOtherPanelScreens(): void
    {
        $app = App::i();
        $open = ['index', 'my-account', 'deleteaccount', 'terms', 'requireauth', 'registrations', 'evaluations', 'mdc-tree-curation', 'mdc-committee'];

        $app->hook('GET(panel.<<*>>):before', function () use ($app, $open) {
            $action = strtolower($this->action);

            if (Plugin::isTheme() && in_array($action, Plugin::UNUSED_PANEL_SCREENS, true)) {
                $app->pass();
            }

            if (Plugin::appliesToCurrentUser() && !in_array($action, $open, true)) {
                $app->pass();
            }
        });

        $app->hook('ALL(app.<<*>>):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->pass();
            }
        });
    }

    // No subsite dos Mestres, quem não é admin só vê o selo do programa, e o gestor e o saasSuperAdmin só os selos do
    // subsite (o do programa e os que criarem nele): na API de selos, nos selos que a API de agentes devolve e nas
    // páginas de selo e de certificado. Os selos do agente vêm da consulta do agente, não da API de selos, e são
    // filtrados na saída da API, depois do cache dela, que não separa usuários. O perfil público do mestre já esconde o
    // bloco de selos do core (_mdc-master-profile.scss) e mostra o do programa no cabeçalho.
    private function showOnlyTheProgramSeal(): void
    {
        $app = App::i();
        $programSeal = $this->programSeal();
        $fixedOpportunity = $this->fixedOpportunity();

        // Os ids que a pessoa vê; null, sem filtro (fora do subsite, ou sem o selo do programa configurado).
        $visible = function () use ($app, $programSeal, $fixedOpportunity): ?array {
            if (!Plugin::isTheme()) {
                return null;
            }

            if (!$app->user->is('admin')) {
                return $programSeal->configuredId() ? [$programSeal->configuredId()] : null;
            }

            $subsite = $fixedOpportunity->subsiteId();
            $sql = $subsite === null ? 'SELECT id FROM seal WHERE subsite_id IS NULL' : 'SELECT id FROM seal WHERE subsite_id = :subsite';

            return array_map('intval', $app->conn->fetchFirstColumn($sql, ['subsite' => $subsite]));
        };

        $keepVisibleSeals = function (array &$agents, array $ids): void {
            foreach ($agents as &$agent) {
                foreach (['seals', 'verifiedSeals'] as $key) {
                    if (is_array($agent[$key] ?? null)) {
                        $agent[$key] = array_values(array_filter($agent[$key], fn ($seal) => in_array((int) ($seal['sealId'] ?? 0), $ids, true)));
                    }
                }
            }
        };

        $app->hook('ApiQuery(Seal).params', function (&$params) use ($visible) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || ($ids = $visible()) === null) {
                return;
            }

            $only = API::IN($ids ?: [0]);
            $params['id'] = isset($params['id']) ? API::AND($params['id'], $only) : $only;
        });

        $app->hook('API.<<find|findOne>>(agent).result', function ($params, &$result) use ($visible, $keepVisibleSeals) {
            if (!is_array($result) || ($ids = $visible()) === null) {
                return;
            }

            if (isset($result['id'])) {
                $one = [&$result];
                $keepVisibleSeals($one, $ids);
            } else {
                $keepVisibleSeals($result, $ids);
            }
        });

        // A página do perfil leva o agente num JSON para a tela, montado sem passar pela API.
        $app->hook('view.requestedEntity(Agent).result', function (&$entity) use ($visible, $keepVisibleSeals) {
            if (($ids = $visible()) !== null) {
                $one = [&$entity];
                $keepVisibleSeals($one, $ids);
            }
        });

        $app->hook('GET(seal.<<single|sealRelation|printsealrelation>>):before', function () use ($app, $visible) {
            if (($ids = $visible()) === null) {
                return;
            }

            $id = (int) ($this->data['id'] ?? 0);
            $seal = $this->action === 'single' ? $app->repo('Seal')->find($id) : $app->repo('SealRelation')->find($id)?->seal;

            if (!$seal || !in_array($seal->id, $ids, true)) {
                $app->pass();
            }
        });
    }

    // Ver Services\NotificationScope. No parâmetro da consulta, e não no resultado, para o sino (que conta à parte)
    // bater com a lista. Para todos no subsite dos Mestres, inclusive o gestor e o saasSuperAdmin.
    private function leaveOutOtherProgramsNotifications(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();
        $scope = new Services\NotificationScope($fixedOpportunity);

        $app->hook('ApiQuery(Notification).params', function (&$params) use ($app, $fixedOpportunity, $scope) {
            /** @var ApiQuery $this */
            if ($this->parentQuery || !Plugin::isTheme() || $app->user->is('guest') || !$fixedOpportunity->isConfigured()) {
                return;
            }

            if ($hidden = $scope->hiddenFor($app->user)) {
                $outside = API::NOT_IN($hidden);
                $params['id'] = isset($params['id']) ? API::AND($params['id'], $outside) : $outside;
            }
        });
    }

    // Desligadas no tema (config.php): agentes seguem com os admins; projetos, só com o saasSuperAdmin, que configura a
    // oportunidade do programa (ela pertence a um projeto); espaços não são usados no subsite, por ninguém.
    private function blockDisabledEntities(): void
    {
        $app = \MapasCulturais\App::i();

        $isAllowed = fn (string $entity): bool => $app->isEnabled($entity) || match ($entity) {
            'agents' => $app->user->is('admin'),
            'projects' => $app->user->is('saasSuperAdmin'),
            default => false,
        };

        $isOwnProfile = function (mixed $id) use ($app): bool {
            $profile = $app->user->profile ?? null;

            return $id && $profile && (int) $id === $profile->id;
        };

        // O mestre aprovado tem perfil público: a página do agente que leva o selo do programa abre para todos.
        $plugin = $this;
        $isPublicMaster = fn (string $action, mixed $id): bool => $action === 'single' && $id && $plugin->programSeal()->isGivenTo((int) $id);

        // O próprio perfil fica de fora: toda inscrição pertence a um agente.
        $app->hook('GET(agent.<<*>>):before', function () use ($app, $isAllowed, $isOwnProfile, $isPublicMaster) {
            if ($isAllowed('agents') || $isOwnProfile($this->urlData['id'] ?? null) || $isPublicMaster($this->action, $this->urlData['id'] ?? null)) {
                return;
            }

            $app->pass();
        });

        // ALL alcança todo método HTTP e nunca a API, que gera o prefixo API(...).
        foreach (['space' => 'spaces', 'project' => 'projects'] as $controller => $entity) {
            $app->hook("ALL({$controller}.<<*>>):before", function () use ($app, $entity, $isAllowed) {
                if (!$isAllowed($entity)) {
                    $app->pass();
                }
            });
        }

        $app->hook('GET(<<search|panel>>.<<agents|spaces|projects>>):before', function () use ($app, $isAllowed) {
            if (!$isAllowed($this->action)) {
                $app->pass();
            }
        });
    }
}
