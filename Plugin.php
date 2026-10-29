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
        Hooks\RegistrationForm::register($this);
        Hooks\Management::register($this);
        Hooks\PanelMenu::register($this);
        Hooks\SubsiteIsolation::register($this);
        Hooks\FixedOpportunityLock::register($this);
        Hooks\Masters::register($this);
        Hooks\Moderation::register($this);
        Hooks\Festivities::register($this);
        $this->speakOfCadastro();
        $this->sendTheNetworkMails();
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


    // O e-mail de aprovação; ele sai no gancho da aprovação (applyProgramSeal), depois do selo.
    private function notifyApproval(): void
    {
        App::i()->config['mailer.templates'][Services\Moderation::MAIL_APPROVED] = [
            'title' => i::__('[Rede de Mestras e Mestres] Seu cadastro foi aprovado'),
            'template' => Services\Moderation::MAIL_APPROVED . '.html',
        ];
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
