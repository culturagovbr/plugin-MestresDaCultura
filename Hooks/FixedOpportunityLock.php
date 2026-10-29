<?php

namespace MDC\Hooks;

use MDC\Plugin;
use MDC\Services;
use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

/**
 * Oportunidade do programa: nenhuma outra no subsite dos Mestres, estrutura alterada só pelo saasSuperAdmin e
 * importação completa.
 */
final class FixedOpportunityLock
{
    /** Chave que marca, no arquivo exportado, a oportunidade do programa (ver completeOpportunityImport). */
    private const IMPORT_MARK = 'mdcOpportunity';

    static function register(Plugin $plugin): void
    {
        self::blockOpportunityBrowsing($plugin);
        self::protectFixedOpportunity($plugin);
        self::lockFixedOpportunityStructure($plugin);
        self::completeOpportunityImport($plugin);
    }

    private static function blockOpportunityBrowsing(Plugin $plugin): void
    {
        $app = \MapasCulturais\App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();

        // No subsite, só o saasSuperAdmin cria oportunidade nova.
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

        // No subsite, toda ação do controlador só abre a oportunidade do programa; o saasSuperAdmin abre as do subsite
        // dos Mestres.
        $app->hook('ALL(opportunity.<<*>>):before', function () use ($app, $fixedOpportunity) {
            if (!Plugin::isTheme()) {
                return;
            }

            $requested = $this->urlData['id'] ?? null;

            if (!$requested) {
                return;
            }

            $opportunity = $app->repo('Opportunity')->find((int) $requested);

            if ($app->user->is('saasSuperAdmin')) {
                if ($opportunity && $opportunity->subsite?->id !== $fixedOpportunity->subsiteId()) {
                    $app->pass();
                }

                return;
            }

            // Abrem a oportunidade do programa e todas as fases dela (FixedOpportunity::matches).
            if (!$fixedOpportunity->matches($opportunity)) {
                $app->pass();
            }
        });
    }

    // A oportunidade do programa só é arquivada, excluída, despublicada ou clonada pelo saasSuperAdmin.
    private static function protectFixedOpportunity(Plugin $plugin): void
    {
        $app = App::i();
        $fixedOpportunity = $plugin->fixedOpportunity();
        $guard = new Services\FixedOpportunityGuard($fixedOpportunity);

        $app->hook('entity(Opportunity).canUser(<<archive|remove|destroy|unpublish>>)', function ($user, &$result) use ($guard) {
            /** @var Opportunity $this */
            if ($guard->locks($this, $user)) {
                $result = false;
            }
        });

        // A configuração de avaliação do programa também não é excluída.
        $app->hook('entity(EvaluationMethodConfiguration).canUser(<<remove|destroy>>)', function ($user, &$result) use ($guard) {
            if ($guard->locks($this->opportunity, $user)) {
                $result = false;
            }
        });

        // A raiz do programa só sai do ar pelo saasSuperAdmin.
        $app->hook('entity(Opportunity).setStatus(<<*>>)', function ($status) use ($app, $guard, $fixedOpportunity) {
            /** @var Opportunity $this */
            $isRoot = $this->id && $this->id === $fixedOpportunity->get()?->id;

            if ($status <= 0 && $this->status > 0 && $isRoot && $app->isAccessControlEnabled() && $guard->locks($this, $app->user)) {
                throw new PermissionDenied($app->user, $this, 'unpublish');
            }
        });

        // Com login, clonar, gerar modelo e tornar modelo público são recusados na oportunidade do programa.
        $app->hook('ALL(opportunity.<<duplicate|generatemodel|generateopportunity|modelpublic>>):before', function () use ($app, $guard) {
            if (!$app->user->is('guest') && $guard->locks($this->requestedEntity, $app->user)) {
                throw new PermissionDenied($app->user, $this->requestedEntity, $this->action);
            }
        });

        // A classe `mdc-structure-locked` deixa o tema esconder os botões e a configuração travados.
        $app->hook('GET(opportunity.<<edit|single|formbuilder>>):before', function () use ($app, $guard) {
            if ($guard->locks($this->requestedEntity, $app->user)) {
                $app->view->bodyClasses[] = 'mdc-structure-locked';
            }
        });
    }

    // Fases, datas e formulário da oportunidade do programa só mudam pelo saasSuperAdmin; na comissão, o gestor
    // inclui e desabilita moderadores, sem excluir nem reabrir.
    private static function lockFixedOpportunityStructure(Plugin $plugin): void
    {
        $app = App::i();
        $guard = new Services\FixedOpportunityGuard($plugin->fixedOpportunity());

        // Fase nova (oportunidade filha ou configuração de avaliação) não é criada na oportunidade do programa.
        $app->hook('entity(<<Opportunity|EvaluationMethodConfiguration>>).canUser(create)', function ($user, &$result) use ($guard) {
            $opportunity = $this instanceof Opportunity ? $this : $this->opportunity;

            if (!$this->id && $guard->locks($opportunity, $user)) {
                $result = false;
            }
        });

        // Trava os campos e os anexos do formulário.
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

        // Reabrir avaliações pela oportunidade e importar campos são recusados na oportunidade do programa.
        $app->hook('POST(opportunity.<<importfields|reopenevaluations>>):before', function () use ($app, $guard) {
            $id = strtolower($this->action) === 'importfields' ? ($this->urlData['id'] ?? 0) : ($this->data['opportunityId'] ?? 0);
            $opportunity = $app->repo('Opportunity')->find((int) $id);

            if ($guard->locks($opportunity, $app->user)) {
                throw new PermissionDenied($app->user, $opportunity, $this->action);
            }
        });

        // Cada data mudada em relação à gravada é recusada: erro de validação na tela e recusa ao salvar.
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

    // A importação da oportunidade do programa traz a autoaplicação de resultados, os campos que o avaliador vê e o
    // fluxo contínuo, e publica o resultado; a comissão fica de fora.
    private static function completeOpportunityImport(Plugin $plugin): void
    {
        $app = App::i();

        // A marca vai na exportação da fase de avaliação da oportunidade do programa.
        $app->hook('evaluationMethod(<<*>>).export:after', function (&$result) use ($plugin) {
            /** @var \MapasCulturais\Entities\EvaluationMethodConfiguration $this */
            if ($plugin->fixedOpportunity()->matches($this->opportunity)) {
                $result[self::IMPORT_MARK] = [
                    'isContinuousFlow' => (bool) $this->opportunity->firstPhase->isContinuousFlow,
                    'hasEndDate' => (bool) $this->opportunity->firstPhase->hasEndDate,
                ];
            }
        });

        // No arquivo com a marca, a fase de avaliação e a oportunidade importadas recebem o que o exportador gravou.
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

            // Todas as fases recebem o fluxo contínuo e a data de término marcados.
            foreach ($opportunity->allPhases as $phase) {
                $phase->isContinuousFlow = $mark['isContinuousFlow'];
                $phase->hasEndDate = $mark['hasEndDate'];
                $phase->save(true);
            }

            // A oportunidade importada nasce com o resultado publicado: cada resultado aparece ao inscrito assim que aplicado.
            $opportunity->publishRegistrations();
        });
    }
}
