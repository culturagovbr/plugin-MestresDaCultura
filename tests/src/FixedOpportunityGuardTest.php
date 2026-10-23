<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class FixedOpportunityGuardTest extends TestCase
{
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    private const REMOVAL = ['archive', 'remove', 'destroy'];

    /** A oportunidade com a fase de avaliação (que fica na raiz) e uma fase filha, criada como no programa. */
    private function createOpportunity(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        $builder = $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save();

        $builder->addEvaluationPhase(EvaluationMethods::documentary)
            ->setEvaluationPeriod(new ConcurrentEndingAfter)
            ->save()
            ->done();

        $builder->addDataCollectionPhase()
            ->setRegistrationPeriod(new Open)
            ->save()
            ->done();

        return $builder->refresh()->getInstance();
    }

    private function phaseOf(Opportunity $opportunity): Opportunity
    {
        $phase = current(array_filter($opportunity->allPhases, fn ($phase) => $phase->id !== $opportunity->id));
        $this->assertInstanceOf(Opportunity::class, $phase, 'A oportunidade de teste precisa de uma fase filha');

        return $phase;
    }

    private function statusOf(string $action, Opportunity $opportunity, User $user): int
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run($this->requestFactory->POST('opportunity', $action, [$opportunity->id], ajax: true), false);

        return $this->app->response->getStatusCode();
    }

    function testRemovalIsRefusedOnTheFixedOpportunityAndItsPhasesToWhoIsNotSuperAdmin()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');

        foreach ([$fixed, $this->phaseOf($fixed)] as $opportunity) {
            foreach (self::REMOVAL as $action) {
                $this->assertFalse($opportunity->canUser($action, $admin), "O gestor não pode {$action} a oportunidade do programa ({$opportunity->id})");
            }
        }
    }

    function testSuperAdminAndOtherOpportunitiesFollowTheCore()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');
        $superAdmin = $this->userDirector->createUser('saasSuperAdmin');

        foreach (self::REMOVAL as $action) {
            $this->assertTrue($fixed->canUser($action, $superAdmin), "O saasSuperAdmin segue podendo {$action} a oportunidade do programa");
            $this->assertTrue($other->canUser($action, $admin), "Em outra oportunidade, {$action} segue como no core");
        }
    }

    // Só o saasSuperAdmin duplica ou gera modelo da oportunidade do programa, dentro e fora do subsite.
    function testDuplicateAndModelAreRefusedOnTheFixedOpportunityToWhoIsNotSuperAdmin()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');

        foreach (['duplicate', 'generatemodel', 'generateopportunity', 'modelpublic'] as $action) {
            $this->assertEquals(403, $this->statusOf($action, $fixed, $admin), "O gestor não pode {$action} a oportunidade do programa");
        }

        $this->assertEquals(200, $this->statusOf('duplicate', $fixed, $this->userDirector->createUser('saasSuperAdmin')), 'O saasSuperAdmin segue podendo duplicar');
    }

    // A fase segue protegida com o pai trocado e ainda não gravado.
    function testAPhaseStaysProtectedAfterItsParentIsUnset()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');
        $phase = $this->phaseOf($fixed);

        $this->login($admin);
        $phase->setParent(null);

        $this->assertFalse($phase->canUser('remove', $admin), 'A fase segue protegida com o pai trocado e ainda não gravado');
    }

    // O gestor não remove a configuração de avaliação, que é a fase de moderação.
    function testTheEvaluationPhaseCannotBeRemovedByTheManager()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');

        $this->assertFalse($fixed->evaluationMethodConfiguration->canUser('remove', $admin), 'O gestor não exclui a fase de moderação');
        $this->assertTrue($fixed->evaluationMethodConfiguration->canUser('remove', $this->userDirector->createUser('saasSuperAdmin')));
    }

    // Só o saasSuperAdmin despublica ou desativa a oportunidade do programa.
    function testTakingTheFixedOpportunityOffIsRefusedToWhoIsNotSuperAdmin()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');

        $this->assertFalse($fixed->canUser('unpublish', $admin), 'O gestor não despublica a oportunidade do programa');

        $this->login($admin);
        foreach ([Opportunity::STATUS_DRAFT, Opportunity::STATUS_DISABLED] as $status) {
            $this->assertException(PermissionDenied::class, fn () => $fixed->setStatus($status), "O gestor não põe a oportunidade do programa no status {$status}");
        }

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $fixed->setStatus(Opportunity::STATUS_DRAFT);
        $this->assertEquals(Opportunity::STATUS_DRAFT, $fixed->status, 'O saasSuperAdmin segue podendo tirar do ar');
    }
}
