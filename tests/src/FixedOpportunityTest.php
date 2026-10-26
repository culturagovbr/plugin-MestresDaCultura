<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

class FixedOpportunityTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use RegistrationDirector;
    use UserDirector;

    private function withConfiguredId(mixed $id): FixedOpportunity
    {
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $id;

        return new FixedOpportunity();
    }

    private function createOpportunity(): Opportunity
    {
        $user = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($user);

        return $this->opportunityBuilder
            ->reset(owner: $user->profile, owner_entity: $user->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    function testResolvesTheConfiguredOpportunity()
    {
        $opportunity = $this->createOpportunity();

        $this->assertEquals(
            $opportunity->id,
            $this->withConfiguredId($opportunity->id)->get()?->id,
            'A chave configurada precisa resolver na oportunidade correspondente'
        );
    }

    function testReturnsNullWhenTheKeyIsAbsent()
    {
        $log = $this->captureLog();

        $this->assertNull(
            $this->withConfiguredId(null)->get(),
            'Sem chave configurada o serviço devolve nulo, sem quebrar a aplicação'
        );

        $this->assertTrue(
            $log->hasErrorThatContains('não está configurada'),
            'A causa precisa ficar evidente no log, e distinguir chave ausente de id inexistente'
        );
    }

    function testReturnsNullWhenTheIdDoesNotExist()
    {
        $log = $this->captureLog();

        $this->assertNull(
            $this->withConfiguredId(999999999)->get(),
            'Id inexistente devolve nulo, sem quebrar a aplicação'
        );

        $this->assertTrue(
            $log->hasErrorThatContains('999999999'),
            'O log precisa dizer qual id não foi encontrado'
        );
    }

    function testResolvesOnlyOncePerConfiguredId()
    {
        $opportunity = $this->createOpportunity();
        $service = $this->withConfiguredId($opportunity->id);

        $this->assertSame($service->get(), $service->get(), 'A segunda chamada vem do cache');
    }

    // Depois de o gerenciador do Doctrine ser limpo, a oportunidade é buscada de novo.
    function testAfterTheEntityManagerIsClearedItIsResolvedAgain()
    {
        $opportunity = $this->createOpportunity();
        $service = $this->withConfiguredId($opportunity->id);

        $service->get();
        $this->app->em->clear();

        $this->assertTrue($this->app->em->contains($service->get()), 'A oportunidade volta a ser a do gerenciador');
    }

    // Com outro id configurado, a oportunidade é buscada de novo.
    function testAnotherConfiguredIdIsResolvedAgain()
    {
        $first = $this->createOpportunity();
        $second = $this->createOpportunity();
        $service = $this->withConfiguredId($first->id);

        $service->get();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $second->id;

        $this->assertSame($second->id, $service->get()?->id);
    }

    function testIncludesOnlyTheRegistrationsOfTheFixedOpportunity()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $service = $this->withConfiguredId($fixed->id);

        $this->assertTrue(
            $service->includes($this->registrationDirector->createDraftRegistrations($fixed, 1)[0]),
            'A inscrição da oportunidade fixa faz parte dela'
        );

        $this->assertFalse(
            $service->includes($this->registrationDirector->createDraftRegistrations($other, 1)[0]),
            'A inscrição de outra oportunidade da instalação fica de fora'
        );
    }
}
