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
        Hooks\Tree::register($this);
        $this->renderActingField();
        $this->validateActingFieldOnSend();
        $this->validatePrizeIdOnSend();
        $this->freezeActingFieldOnSend();
        $this->showFieldsOnV1Reading();
        $this->renderPhotoViewer();
        $this->showActingFieldAsTextInPdf();
        Hooks\Management::register($this);
        $this->addManagementShortcuts();
        $this->addModerationShortcut();
        $this->blockDisabledEntities();
        Hooks\FixedOpportunityLock::register($this);
        $this->scopeRegistrationsToFixedOpportunity();
        $this->sendForeignRegistrationsHome();
        $this->keepTheProgramOnItsSite();
        $this->closeOtherPanelScreens();
        $this->showOnlyTheProgramSeal();
        $this->leaveOutOtherProgramsNotifications();
        $this->limitToOneRegistrationPerUser();
        $this->addTraditionalCommunities();
        $this->sendEachEvaluationOnce();
        $this->passOnTheDisabledModeratorsWork();
        $this->showRoleBlocksInThePanel();
        $this->sendTheMasterToThePanel();
        $this->fixTheProgramBreadcrumbs();
        $this->nameTheOwnProfile();
        $this->feedTheHomeShowcase();
        $this->openTheMastersSearch();
        $this->skipProfileFieldsTheProgramDoesNotAsk();
        $this->skipEventFieldsTheFestejoDoesNotAsk();
        $this->completeTheFestivityPlace();
        $this->openTheFestivityForm();
        $this->openTheFestivitySearch();
        $this->keepEntitiesInTheSubsite();
        $this->speakOfCadastro();
        $this->sendTheNetworkMails();
        $this->keepOnePlacePerModerator();
        $this->returnRefusedRegistrations();
        $this->reopenEvaluationOnResend();
        $this->validateOnlyTheReopenedFields();
        $this->showModerationToOwner();
        $this->applyProgramSeal();
        $this->showMasterProfile();
        $this->showFestivityProfile();
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

    // No subsite dos Mestres, a mini bio (descrição curta) e a área de atuação não são obrigatórias no agente, como na
    // Pnab: o programa não as usa (a mini-biografia do formulário vai para a descrição longa, e a atuação é a árvore do
    // programa). Na regra (validação e taxonomia), e não só na tela: valem para a criação de conta, para a edição do
    // perfil e para o desvio do core para a edição do perfil (app.redirect_profile_validate). O limite de tamanho da
    // descrição curta, o nome e o perfil em rascunho seguem como no core.
    private function skipProfileFieldsTheProgramDoesNotAsk(): void
    {
        $app = App::i();

        $app->hook('entity(Agent)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        $app->hook('app.register:after', function () use ($app) {
            if (Plugin::isTheme() && ($area = $app->getRegisteredTaxonomies(\MapasCulturais\Entities\Agent::class)['area'] ?? null)) {
                $area->required = false;
            }
        });

        // A tela de criar conta exige as duas no próprio componente (create-account-optional.js).
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'create-account' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-create-account-optional', 'js/create-account-optional.js', ['create-account']);
            }
        }, 1000);
    }

    // O festejo é um Evento do core, e a planilha dos Festejos não pede a descrição curta, a classificação etária, a
    // linguagem nem o valor de entrada da agenda (decisão FJD3). Só no subsite dos Mestres, como os campos do perfil acima.
    private function skipEventFieldsTheFestejoDoesNotAsk(): void
    {
        $app = App::i();

        $app->hook('entity(Event)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        // O período da agenda (FJ-04) também não tem valor de entrada na planilha.
        $app->hook('entity(EventOccurrence)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['price']['required']);
            }
        });

        // Sem o "required", o core marca a coluna pelo `nullable`, e a da descrição curta do evento não é.
        $app->hook('entity(Event).propertiesMetadata', function (&$result) {
            if (Plugin::isTheme()) {
                $result['shortDescription']['required'] = false;
            }
        });

        $app->hook('app.register:after', function () use ($app) {
            if (!Plugin::isTheme()) {
                return;
            }

            if ($age = $app->getRegisteredMetadataByMetakey('classificacaoEtaria', Event::class)) {
                $age->is_required = false;
            }

            if ($language = $app->getRegisteredTaxonomies(Event::class)['linguagem'] ?? null) {
                $language->required = false;
            }
        });
    }

    // O local do festejo só pede o nome e o endereço (ver Services\FestivityPlace): o tipo vem sozinho (com o nome do
    // tipo que o SNIIC pede no "Outros"), e a descrição
    // curta não é obrigatória. A área de atuação já não é (skipProfileFieldsTheProgramDoesNotAsk: a taxonomia é a mesma).
    private function completeTheFestivityPlace(): void
    {
        $app = App::i();
        $plugin = $this;

        $app->hook('POST(space.index):data', function (&$data) use ($app) {
            if (!Plugin::isTheme() || !empty($data['type'])) {
                return;
            }

            $data['type'] = Services\FestivityPlace::type();

            if ($app->getRegisteredMetadataByMetakey(Services\FestivityPlace::OTHER_TYPE_FIELD, \MapasCulturais\Entities\Space::class)) {
                $data[Services\FestivityPlace::OTHER_TYPE_FIELD] ??= i::__('Local de festejo popular');
            }
        });

        $app->hook('entity(Space)::validations', function (array &$validations) {
            if (Plugin::isTheme()) {
                unset($validations['shortDescription']['required']);
            }
        });

        // A UF, a cidade e a região do festejo saem do primeiro local da agenda (FestivityPlace::sync): a cada
        // gravação do festejo, que o core repete a cada período, e quando o endereço de um local muda. Na mesma
        // gravação, libras e audiodescrição marcam os campos do core, o aceite ganha a data do servidor e a
        // instituição sai se o tipo de realizador não a tem.
        $app->hook('entity(Event).save:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::sync($this);
                Services\FestivityFields::syncCore($this);
                Services\FestivityFields::stampConsent($this);
                Services\FestivityFields::clearInstitution($this);
                Services\FestivityFields::markFirstPublished($this);
            }
        });

        // O selo dos festejos e a situação dos locais (FestivityPlace::syncStatus) acompanham a publicação, depois de
        // gravada.
        $app->hook('entity(Event).save:finish', function ($flush = false) use ($plugin) {
            if (Plugin::isFestivity($this)) {
                $plugin->festivitySeal()->sync($this);

                // Sem flush, o banco ainda não tem a gravação: a situação dos locais sai na que vier com ele.
                if ($flush) {
                    Services\FestivityPlace::syncStatus($this);
                }
            }
        });

        $app->hook('entity(EventOccurrence).remove:before', function () {
            if (Plugin::isTheme()) {
                Services\FestivityPlace::release($this);
            }
        });

        // O festejo apagado de vez não é gravado depois (ver FestivityPlace::syncWithout). Senão, o local de um festejo
        // publicado ficaria publicado no Mapa principal, com o endereço e sem festejo. Evento de outro subsite fica fora.
        $app->hook('entity(Event).destroy:before', function () {
            if (Plugin::isFestivity($this)) {
                Services\FestivityPlace::syncWithout($this);
            }
        });

        $app->hook('entity(Event).remove:after', function () {
            Services\FestivityPlace::forgetReleased();
        });

        // O local nasce em rascunho e é publicado com o festejo (FestivityPlace::syncStatus). A situação não se edita
        // (o PATCH e o PUT do core a aceitam): a exclusão é a de "Meus locais", que confere os festejos.
        $app->hook('entity(Space).insert:before', function () {
            if (Plugin::isTheme()) {
                $this->status = \MapasCulturais\Entities\Space::STATUS_DRAFT;
            }
        });

        $app->hook('<<PATCH|PUT>>(space.single):data', function (&$data) {
            if (Plugin::isTheme()) {
                unset($data['status']);
            }
        });

        // O festejo também: o core valida no PATCH só o que foi enviado, e o selo sairia sem as 6 etapas. Publicar é pela
        // rota própria. A data da primeira publicação é do servidor em qualquer subsite (FestivityFields::markFirstPublished).
        $app->hook('<<PATCH|PUT>>(event.single):data', function (&$data) {
            if (Plugin::isTheme()) {
                unset($data['status']);
            }
        });

        $app->hook('<<PATCH|PUT>>(event.single):data, POST(event.index):data', function (&$data) {
            unset($data[Services\FestivityFields::FIRST_PUBLISHED]);
        });

        // A exclusão de "Meus locais" não leva o local de um festejo; o erro diz quais o usam. A permissão é a do core,
        // conferida antes para não mostrar a outra pessoa os festejos do local.
        $app->hook('DELETE(space.single):before', function () {
            $space = $this->requestedEntity;

            if (!Plugin::isTheme() || !$space instanceof \MapasCulturais\Entities\Space || !$space->canUser('remove')) {
                return;
            }

            // O festejo na lixeira não prende: se voltar, a etapa 2 avisa do local excluído (FestivityForm, removedPlace).
            if ($events = array_filter(Services\FestivityPlace::eventsAt($space->id), fn ($event) => $event->status >= \MapasCulturais\Entities\Event::STATUS_DRAFT)) {
                $names = implode(', ', array_map(fn ($event) => $event->name, $events));
                $this->errorJson(['space' => [sprintf(i::__('Este local está na agenda de: %s. Tire-o dos festejos antes de excluir.'), $names)]], 400);
            }
        });

        // O festejo copia do local só a UF e a cidade (FestivityPlace::sync): o resto não regrava os festejos dele. Os
        // metadados alterados seguem marcados até o fim da gravação.
        $app->hook('entity(Space).save:finish', function () use ($app) {
            if (!Plugin::isTheme() || !$this->id || Services\FestivityPlace::$syncing) {
                return;
            }

            if (!array_intersect_key($this->changedMetadata, array_flip(['En_Estado', 'En_Municipio']))) {
                return;
            }

            $app->disableAccessControl();

            try {
                foreach (Services\FestivityPlace::eventsAt($this->id) as $event) {
                    $event->save(true);
                }
            } finally {
                $app->enableAccessControl();
            }
        });
    }

    // O cadastro do festejo em 6 etapas (Controller::GET_festivity, Services\FestivityForm) em `/cadastrar-festejo/`.
    // No subsite dos Mestres todo evento é festejo: a criação e a edição do core levam ao formulário, e publicar, pelo
    // formulário ou pelo painel ("Meus eventos"), cobra as 6 etapas; o festejo publicado também não perde o que elas
    // cobram. O rascunho salva sem cobrar.
    private function openTheFestivityForm(): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['cadastrar-festejo'] = ['mdc', 'festivity'];
        // "Meus locais" (Controller::GET_places), no painel.
        $app->config['routes']['shortcuts']['meus-locais'] = ['mdc', 'places'];

        $isFestivity = fn (Event $event): bool => Plugin::isFestivity($event);

        $app->hook('GET(event.create):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->redirect($app->createUrl('mdc', 'festivity'));
            }
        });

        // O "Criar Evento" de "Meus eventos" abre o modal do core (create-event-festivity.js).
        $app->hook('Theme::enqueueComponentScript', function ($result, string $component) {
            if ($component === 'create-event' && Plugin::isTheme()) {
                $this->enqueueScript('components', 'mdc-create-event-festivity', 'js/create-event-festivity.js', ['create-event']);
            }
        }, 1000);

        $app->hook('GET(event.edit):before', function () use ($app, $isFestivity) {
            $event = $this->requestedEntity;

            if ($event instanceof Event && $isFestivity($event)) {
                $app->redirect($app->createUrl('mdc', 'festivity', [$event->id]));
            }
        });

        // A publicação do core valida o evento ainda em rascunho, antes de mudar o status.
        $publishing = false;
        $app->hook('ALL(event.publish):before', function () use (&$publishing) {
            $publishing = true;
        });

        $app->hook('entity(Event).validationErrors', function (array &$errors) use (&$publishing, $isFestivity) {
            if (!$isFestivity($this)) {
                return;
            }

            $errors += Services\FestivityForm::invalid($this);

            if ($publishing || $this->status > Event::STATUS_DRAFT) {
                $errors += Services\FestivityForm::missing($this);
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

    // A vitrine da home (Services\MasterShowcase): o tema mostra (home-mestres, home-banner); os dados vão no objeto
    // da página, como os de "Meus cadastros". O mapa da Rede (mdc-network-map) recebe o desenho dos estados, da API de
    // malhas do IBGE (data/br-uf.json), e quantos há em cada um.
    private function feedTheHomeShowcase(): void
    {
        App::i()->hook('view.render(site/index):before', function () {
            if (Plugin::isTheme()) {
                $showcase = new Services\MasterShowcase;
                $this->jsObject['config']['mdcShowcase'] = [
                    'highlights' => $showcase->highlights(),
                    // Os festejos publicados (FJ-09) junto dos contadores da Rede.
                    'counts' => $showcase->counts() + ['festivities' => (new Services\FestivitySeal)->countPublished()],
                    'states' => (object) $showcase->byState(),
                    'map' => json_decode((string) file_get_contents(__DIR__ . '/data/br-uf.json'), true),
                    'searchUrl' => App::i()->createUrl('mdc', 'masters'),
                ];
            }
        });
    }

    // A busca de Mestras e Mestres (Controller::GET_masters, mdc-masters-search) em `/mestras-e-mestres/`, como o
    // AldirBlanc registra as rotas dele. A busca por nome procura também o nome artístico, público, pelos ganchos
    // que o core usa para os metadados do agente (módulo ApiKeywords).
    private function openTheMastersSearch(): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['mestras-e-mestres'] = ['mdc', 'masters'];

        $app->hook('repo(agent).getIdsByKeywordDQL.join', function (&$joins) {
            if (Plugin::isTheme()) {
                $joins .= "\n LEFT JOIN e.__metadata mdc_known_as WITH mdc_known_as.key = '" . Services\MasterPublicData::KNOWN_AS . "'";
            }
        });

        $app->hook('repo(agent).getIdsByKeywordDQL.where', function (&$where, $keyword, $alias) {
            if (Plugin::isTheme()) {
                $where .= " OR unaccent(lower(mdc_known_as.value)) LIKE unaccent(lower(:{$alias}))";
            }
        });
    }

    // O agente da mestra e do moderador é o perfil deles (Meu perfil, no menu). O core o trata como um agente
    // qualquer: o caminho passa por "Meus agentes", lista fechada no painel (404), o botão diz "Editar agente" e a
    // edição, "Edição do agente individual". Para quem segue o vocabulário do programa (D5); o gestor fica com o core.
    // O perfil público da mestra com o selo, para os demais, passa pela busca de Mestras e Mestres.
    private function nameTheOwnProfile(): void
    {
        $app = App::i();
        $plugin = $this;

        $isOwnProfile = function ($view) use ($app): bool {
            $agent = $view->controller->requestedEntity ?? null;

            return Plugin::isTheme() && $view->controller->id === 'agent' && $agent instanceof Agent
                && !$app->user->is('guest') && Services\ProgramVocabulary::appliesTo($app->user) && $agent->id === $app->user->profile?->id;
        };

        $app->hook('mapas.printJsObject:before', function () use ($isOwnProfile, $plugin) {
            $agent = $this->controller->requestedEntity ?? null;

            if ($isOwnProfile($this) && in_array($this->controller->action, ['single', 'edit'], true)) {
                $breadcrumb = Services\ProgramBreadcrumb::profile($agent->singleUrl, $this->controller->action === 'edit');
            } elseif (Plugin::isTheme() && $this->controller->id === 'agent' && $this->controller->action === 'single'
                && $agent instanceof Agent && $agent->id && $plugin->programSeal()->isGivenTo($agent->id)) {
                // O perfil público da mestra, para quem não é ela (ver ProgramBreadcrumb::master).
                $breadcrumb = Services\ProgramBreadcrumb::master($agent->{Services\MasterPublicData::KNOWN_AS} ?: $agent->name);
            } else {
                return;
            }

            $this->breadcrumb = $breadcrumb;

            if (isset($this->jsObject['breadcrumb'])) {
                $this->jsObject['breadcrumb'] = $breadcrumb;
            }
        });

        $app->hook('component(agent.single.entity-actions).texts', function (array &$texts) use ($isOwnProfile) {
            if ($isOwnProfile($this)) {
                $texts['Agente'] = i::__('perfil');
            }
        });

        $app->hook('component(agent.edit.entity-header).texts', function (array &$texts) use ($isOwnProfile) {
            if ($isOwnProfile($this)) {
                $texts['title agent-1'] = i::__('Edição do perfil');
            }
        });
    }

    // A página da oportunidade do programa é do edital, e não da mestra: o core manda para ela quem sai do formulário
    // ("Sair", "Salvar e sair"). Quem não controla o programa vai para o painel, "Meus cadastros"; o gestor e o
    // saasSuperAdmin seguem vendo a página. Como o RCV leva as páginas do programa para o subsite dele.
    private function sendTheMasterToThePanel(): void
    {
        $app = App::i();
        $fixedOpportunity = $this->fixedOpportunity();

        $app->hook('GET(opportunity.single):before', function () use ($app, $fixedOpportunity) {
            $opportunity = $this->requestedEntity;

            // Aberta em outro subsite, o redirecionamento para o subsite dos Mestres vem antes (ver Services\SubsiteRedirect).
            // A oportunidade desta requisição, e não a guardada no serviço: é ela que diz o subsite e a permissão de agora.
            if (Plugin::isTheme() && $fixedOpportunity->isConfigured() && $fixedOpportunity->matches($opportunity)
                && Services\SubsiteRedirect::isCurrent($opportunity->subsite) && !$opportunity->canUser('@control')) {
                $app->redirect($app->createUrl('panel', 'index'));
            }
        });
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

        // Os festejos da mestra (FJ-10) vêm logo depois da atuação.
        $parts = [
            'entity-header):end' => ['header'],
            'single1-entity-info-taxonomie-area):before' => ['certificate'],
            'entity-gallery-video):before' => ['acting', 'festivities'],
        ];

        foreach ($parts as $hook => $hookParts) {
            $app->hook("template(agent.single.{$hook}", function () use ($render, $hookParts) {
                foreach ($hookParts as $part) {
                    $render->call($this, $part);
                }
            });
        }
    }


    // O subsite dos Mestres só filtra por subsite os eventos, as oportunidades e os projetos (filter_subsite_* da
    // configuração do subsite); os espaços e as ocorrências vinham de todos os subsites, inclusive as ocorrências de
    // rascunhos. Aqui, como o core faz com os eventos (Subsite::applyFilters): só os do subsite atual. Os agentes seguem
    // como no core: o perfil da mestra pode ter nascido em outro subsite, e as telas do programa já filtram pelo selo.
    private function keepEntitiesInTheSubsite(): void
    {
        $app = App::i();
        $current = fn () => $app->subsite?->id ? API::EQ($app->subsite->id) : API::NULL();

        $app->hook('ApiQuery(space).params', function (&$params) use ($current) {
            if (Plugin::isTheme()) {
                $params['_subsiteId'] = $current();
            }
        });

        // A agenda de um festejo que a pessoa pode ver, rascunho incluído, é como o formulário e a página a leem
        // (event: EQ(id)). Fora isso, só as dos festejos publicados do subsite.
        $app->hook('ApiQuery(eventoccurrence).params', function (&$params) use ($app, $current) {
            /** @var ApiQuery $this */
            if (!Plugin::isTheme() || $this->parentQuery) {
                return;
            }

            if (preg_match('/^EQ\((\d+)\)$/', (string) ($params['event'] ?? ''), $match)) {
                $event = $app->repo('Event')->find((int) $match[1]);

                if ($event && Plugin::isFestivity($event) && $event->canUser('view')) {
                    return;
                }
            }

            $this->addFilterByApiQuery(new ApiQuery(Event::class, ['_subsiteId' => $current(), 'status' => API::EQ(Event::STATUS_ENABLED)]), 'id', 'event');
        });

        // O evento de outro subsite abria aqui, vazio (a API dele é filtrada): vai para o subsite dele, como o cadastro.
        $app->hook('GET(event.<<single|edit>>):before', function () use ($app) {
            $event = $this->requestedEntity;

            if (Plugin::isTheme() && $event instanceof Event && $event->id && !Services\SubsiteRedirect::isCurrent($event->subsite)) {
                $app->redirect(Services\SubsiteRedirect::urlOn($event->subsite, $this));
            }
        });

        // A busca de eventos do core repetiria a de festejos.
        $app->hook('GET(search.events):before', function () use ($app) {
            if (Plugin::isTheme()) {
                $app->redirect($app->createUrl('mdc', 'festivities'));
            }
        });

        // Só os próprios locais do subsite. Sem permissão no local, o core gravaria o período como pendente
        // (EventOccurrence::save), e ele apareceria em "Meus locais" da dona do local (revisão do FJ-13).
        $app->hook('entity(EventOccurrence).validationErrors', function (array &$errors) {
            if (!$this->event || !$this->space || !Plugin::isFestivity($this->event)) {
                return;
            }

            if (!Services\SubsiteRedirect::isCurrent($this->space->subsite)) {
                $errors['space'] = [i::__('Escolha um local cadastrado no subsite dos Mestres.')];
            } elseif (!$this->space->canUser('modify')) {
                $errors['space'] = [i::__('Escolha um dos seus locais ou cadastre um novo.')];
            }
        });
    }

    // A busca de festejos (Controller::GET_festivities, mdc-festivities-search) em `/festejos-populares/`, como a de
    // Mestras e Mestres. O mês da agenda vira a lista dos festejos com período nele (FestivitySearch), e a página do
    // festejo ganha o caminho pela busca.
    private function openTheFestivitySearch(): void
    {
        $app = App::i();
        $app->config['routes']['shortcuts']['festejos-populares'] = ['mdc', 'festivities'];

        $app->hook('ApiQuery(Event).params', function (&$params) {
            /** @var ApiQuery $this */
            if (!array_key_exists(Services\FestivitySearch::MONTH_PARAM, $params)) {
                return;
            }

            $month = (int) $params[Services\FestivitySearch::MONTH_PARAM];
            unset($params[Services\FestivitySearch::MONTH_PARAM]);

            if ($month >= 1 && $month <= 12) {
                $params['id'] = API::IN(Services\FestivitySearch::idsInMonth($month) ?: [0]);
            }
        });

        $app->hook('mapas.printJsObject:before', function () {
            $event = $this->controller->requestedEntity ?? null;

            if ($this->controller->id === 'event' && $this->controller->action === 'single' && $event instanceof Event && Plugin::isFestivity($event)) {
                $this->breadcrumb = Services\ProgramBreadcrumb::festivity((string) $event->name);
                if (isset($this->jsObject['breadcrumb'])) {
                    $this->jsObject['breadcrumb'] = $this->breadcrumb;
                }
            }
        });
    }

    // A página do festejo (tela 2 do protótipo): as seções do programa nos ganchos da página de evento do core, como o
    // perfil da mestra (showMasterProfile), com o que o servidor monta só do que é público (Services\FestivityProfile).
    private function showFestivityProfile(): void
    {
        $app = App::i();
        $plugin = $this;
        $profile = false;

        $render = function (string $part) use ($plugin, &$profile) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $event = $this->controller->requestedEntity;

            if ($profile === false) {
                $profile = $event instanceof Event && Plugin::isFestivity($event)
                    ? (new Services\FestivityProfile($plugin->festivitySeal(), $plugin->programSeal()))->of($event)
                    : null;
            }

            if (!$profile) {
                return;
            }

            $this->import('mdc-festivity-profile');
            $json = htmlspecialchars(json_encode($profile), ENT_QUOTES);

            echo "<mdc-festivity-profile part=\"{$part}\" :profile=\"{$json}\"></mdc-festivity-profile>";
        };

        $parts = [
            'entity-header):end' => 'header',
            'entity-gallery-video):before' => 'main',
            'entity-seals):before' => 'aside',
        ];

        foreach ($parts as $hook => $part) {
            $app->hook("template(event.single.{$hook}", function () use ($render, $part) {
                $render->call($this, $part);
            });
        }
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
            // Depois dos festejos, o último grupo do programa no menu do tema.
            $position = array_search('mdc-festejos', array_keys($nav), true);
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
    // No subsite dos Mestres todo evento é festejo (ver openTheFestivityForm); o de outro subsite, aberto daqui, não.
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
        // "Meus eventos" é a lista dos festejos de quem cadastra (ver openTheFestivityForm).
        $open = ['index', 'my-account', 'deleteaccount', 'terms', 'requireauth', 'registrations', 'evaluations', 'events', 'mdc-tree-curation', 'mdc-committee'];

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
        $festivitySeal = $this->festivitySeal();
        $fixedOpportunity = $this->fixedOpportunity();

        // Os ids que a pessoa vê; null, sem filtro (fora do subsite, ou sem o selo do programa configurado). Quem não é
        // admin vê o selo dos mestres e o dos festejos.
        $visible = function () use ($app, $programSeal, $festivitySeal, $fixedOpportunity): ?array {
            if (!Plugin::isTheme()) {
                return null;
            }

            if (!$app->user->is('admin')) {
                return $programSeal->configuredId() ? array_values(array_filter([$programSeal->configuredId(), $festivitySeal->configuredId()])) : null;
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

        // Em qualquer subsite e nos jobs: a notificação chega ao sino dos Mestres de onde vier. A Notification do core
        // não tem os ganchos do Doctrine (insert, remove), só os do save; ler uma notificação também regrava, e o cálculo
        // é refeito uma vez. A excluída não avisa, mas o id dela na lista de escondidas não atrapalha.
        $app->hook('entity(Notification).save:finish', function () {
            if ($this->user?->id) {
                Services\NotificationScope::changed($this->user->id);
            }
        });

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

        // O local do festejo: quem tem conta grava o próprio espaço (ver Services\FestivityPlace).
        $isFestivityPlace = fn (string $controller, string $action): bool => $controller === 'space'
            && !$app->user->is('guest') && Services\FestivityPlace::isWrite($app->request->getMethod(), $action);

        // ALL alcança todo método HTTP e nunca a API, que gera o prefixo API(...).
        foreach (['space' => 'spaces', 'project' => 'projects'] as $controller => $entity) {
            $app->hook("ALL({$controller}.<<*>>):before", function () use ($app, $controller, $entity, $isAllowed, $isFestivityPlace) {
                if (!$isAllowed($entity) && !$isFestivityPlace($controller, $this->action)) {
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
