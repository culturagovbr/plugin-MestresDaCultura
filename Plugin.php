<?php

namespace MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Definitions\RegistrationFieldType;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

// O autoloader tem mapa plano e registra temas antes de plugins: repetir `MestresDaCultura` faria
// o plugin ser ignorado sem erro, e nenhuma classe ou subpasta pode conter `MDC` no nome.
class Plugin extends \MapasCulturais\Plugin
{
    /** Povos e comunidades da planilha do programa que a lista do core não tem. */
    const TRADITIONAL_COMMUNITIES = ['Castanheiras', 'Isqueiros', 'Piaçaveiros', 'Seringueiros'];

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
        $this->freezeActingFieldOnSend();
        $this->showFieldsOnV1Reading();
        $this->renderPhotoViewer();
        $this->showActingFieldAsTextInPdf();
        $this->adjustRegistrationsSpreadsheet();
        $this->addTreeCurationPage();
        $this->blockDisabledEntities();
        $this->blockOpportunityBrowsing();
        $this->limitToOneRegistrationPerUser();
        $this->addTraditionalCommunities();
        $this->completeOpportunityImport();
        $this->returnRefusedRegistrations();
        $this->reopenEvaluationOnResend();
        $this->showModerationToOwner();
        $this->applyProgramSeal();
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
            if ($app->view instanceof \MestresDaCultura\Theme && !$app->repo('SealRelation')->find((int) ($this->data['id'] ?? 0))) {
                $app->pass();
            }
        });
    }

    // Cadastro aprovado na moderação: o mestre é avisado (ver Services\Moderation).
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
        });

        $app->hook("{$job}.getBatch:before", function ($job, &$query) use ($spreadsheet) {
            $spreadsheet->startBatch($job->owner, $query);
        });

        $app->hook('entity(Opportunity).registrationMetadata', function () use ($spreadsheet) {
            /** @var Opportunity $this */
            $spreadsheet->readAsCells($this);
        });

        $app->hook("{$job}.getBatch:after", function () use ($spreadsheet) {
            $spreadsheet->finishBatch();
        });
    }

    // Curadoria da árvore: página no painel e item no grupo de administração, só para administradores e
    // só no subsite do programa.
    private function addTreeCurationPage(): void
    {
        $app = App::i();
        $isTheme = fn (): bool => $app->view instanceof \MestresDaCultura\Theme;

        $app->hook('GET(panel.mdc-tree-curation)', function () use ($app, $isTheme) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!$isTheme()) {
                $app->pass();
            }

            if (!Services\Curators::includes()) {
                $app->halt(403, i::__('Acesso restrito.'));
            }

            $app->view->enqueueScript('app-v2', 'mdc-tree-curation', 'js/tree-curation.js');

            $this->render('mdc-tree-curation');
        });

        $app->hook('panel.nav', function (&$nav) use ($app, $isTheme) {
            if (!$isTheme() || !isset($nav['admin'])) {
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

        $isTheme = fn (): bool => $app->view instanceof \MestresDaCultura\Theme;

        // O plugin roda na instalação inteira; a oportunidade do programa nasce em outro subsite.
        $app->hook('entity(Opportunity).canUser(create)', function ($user, &$result) use ($isTheme) {
            if ($isTheme() && !$user->is('saasSuperAdmin')) {
                $result = false;
            }
        });

        // O tema roda sobre uma oportunidade fixa: não há o que listar.
        $app->hook('GET(<<search|panel>>.opportunities):before', function () use ($app, $isTheme) {
            if ($isTheme() && !$app->user->is('admin')) {
                $app->pass();
            }
        });

        // Esconder o link não fecha a porta: sem isto, qualquer oportunidade da instalação
        // abre por URL direta. Alcança toda ação do controlador, e não só a página.
        $app->hook('ALL(opportunity.<<*>>):before', function () use ($app, $isTheme) {
            if (!$isTheme() || $app->user->is('admin')) {
                return;
            }

            $requested = $this->urlData['id'] ?? null;

            if (!$requested) {
                return;
            }

            $opportunity = $app->repo('Opportunity')->find((int) $requested);
            $fixed = (new Services\FixedOpportunity)->get();

            // A fase é filha da raiz e precisa passar junto: é ela que publica o resultado.
            $root = $opportunity?->parent ?: $opportunity;

            if (!$fixed || !$root || $root->id !== $fixed->id) {
                $app->pass();
            }
        });
    }

    private function blockDisabledEntities(): void
    {
        $app = \MapasCulturais\App::i();

        $isAllowed = fn (string $entity): bool => $app->isEnabled($entity) || $app->user->is('admin');

        $isOwnProfile = function (mixed $id) use ($app): bool {
            $profile = $app->user->profile ?? null;

            return $id && $profile && (int) $id === $profile->id;
        };

        // O próprio perfil fica de fora: toda inscrição pertence a um agente.
        $app->hook('GET(agent.<<*>>):before', function () use ($app, $isAllowed, $isOwnProfile) {
            if ($isAllowed('agents') || $isOwnProfile($this->urlData['id'] ?? null)) {
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
