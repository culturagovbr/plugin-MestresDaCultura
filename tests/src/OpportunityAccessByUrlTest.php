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
    use CapturesLog;
    use OpportunityBuilder;
    use ProgramSubsite;
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

    // O gestor do subsite (admin), como o mestre, só alcança o programa.
    function testTheManagerReachesOnlyTheProgram()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->configure($fixed);
        $this->login($this->userDirector->createUser('admin'));

        $this->assertEquals(404, $this->statusOf('single', $other->id), 'Outra oportunidade não abre para o gestor');
        $this->assertNotEquals(404, $this->statusOf('single', $fixed->id), 'O programa abre');
    }

    // O saasSuperAdmin abre as oportunidades do subsite dos Mestres, e não as dos outros.
    function testTheSaasSuperAdminReachesOnlyTheProgramSubsite()
    {
        $fixed = $this->createOpportunity();
        $sibling = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->configure($fixed);
        $subsite = $this->createProgramSubsite($fixed->owner->id);
        $this->putInSubsite('opportunity', $fixed->id, $subsite);
        $this->putInSubsite('opportunity', $sibling->id, $subsite);
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertNotEquals(404, $this->statusOf('single', $sibling->id), 'Outra oportunidade do subsite dos Mestres abre');
        $this->assertEquals(404, $this->statusOf('edit', $other->id), 'A de outro subsite não abre');
    }

    function testEverythingIsBlockedWhenTheOpportunityIsNotConfigured()
    {
        $this->captureLog();
        $other = $this->createOpportunity();
        $this->configure(null);
        $this->login($this->userDirector->createUser());

        $this->assertEquals(
            404,
            $this->statusOf('single', $other->id),
            'Sem oportunidade configurada nenhuma oportunidade abre para usuário comum'
        );
    }

    // Quem não controla o programa vai da página da oportunidade do programa para o painel, "Meus cadastros".
    function testWhoDoesNotControlTheProgramGoesToThePanel()
    {
        $fixed = $this->createOpportunity();
        $this->configure($fixed);

        $this->login($this->userDirector->createUser());
        $this->assertSame(302, $this->statusOf('single', $fixed->id), 'A mestra é levada ao painel');
        $this->assertSame($this->app->createUrl('panel', 'index'), $this->app->response->getHeaderLine('Location'));

        foreach (['admin', 'saasSuperAdmin'] as $role) {
            $this->login($this->userDirector->createUser($role));
            $this->assertSame(200, $this->statusOf('single', $fixed->id), "Quem controla o programa ({$role}) segue vendo a página");
        }
    }

    private function statusOf(string $action, int $id): int
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('opportunity', $action, [$id]), false);

        return $this->app->response->getStatusCode();
    }
}
