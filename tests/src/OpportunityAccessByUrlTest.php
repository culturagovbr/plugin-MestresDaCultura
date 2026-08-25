<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class OpportunityAccessByUrlTest extends TestCase
{
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    private const ACTIONS = ['single', 'edit', 'registrations'];

    private function createOpportunity(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        return $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    private function configure(?Opportunity $opportunity): void
    {
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity?->id;
    }

    function testTheFixedOpportunityRemainsReachable()
    {
        $fixed = $this->createOpportunity();
        $this->configure($fixed);
        $this->login($this->userDirector->createUser());

        foreach (self::ACTIONS as $action) {
            $this->assertNotEquals(
                404,
                $this->statusOf($action, $fixed->id),
                "A oportunidade do programa não pode ser bloqueada em {$action}"
            );
        }
    }

    function testAnotherOpportunityIsRefusedByDirectUrl()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->configure($fixed);
        $this->login($this->userDirector->createUser());

        foreach (self::ACTIONS as $action) {
            $this->assertEquals(
                404,
                $this->statusOf($action, $other->id),
                "Oportunidade de fora do programa não pode abrir em {$action}"
            );
        }
    }

    // A fase é filha da oportunidade fixa e precisa passar junto: é ela que o core usa na
    // publicação do resultado.
    function testThePhasesOfTheFixedOpportunityRemainReachable()
    {
        $fixed = $this->createOpportunity();
        $this->configure($fixed);
        $this->login($this->userDirector->createUser());

        $phase = $fixed->lastPhase;

        $this->assertNotEquals(404, $this->statusOf('single', $phase->id), 'A fase da oportunidade fixa precisa abrir');
    }

    function testAdminIsNotBlocked()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->configure($fixed);
        $this->login($this->userDirector->createUser('admin'));

        $this->assertNotEquals(
            404,
            $this->statusOf('single', $other->id),
            'Administrador precisa alcançar qualquer oportunidade'
        );
    }

    function testEverythingIsBlockedWhenTheOpportunityIsNotConfigured()
    {
        $other = $this->createOpportunity();
        $this->configure(null);
        $this->login($this->userDirector->createUser());

        $this->assertEquals(
            404,
            $this->statusOf('single', $other->id),
            'Sem oportunidade configurada nenhuma oportunidade abre para usuário comum'
        );
    }

    private function statusOf(string $action, int $id): int
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('opportunity', $action, [$id]), false);

        return $this->app->response->getStatusCode();
    }
}
