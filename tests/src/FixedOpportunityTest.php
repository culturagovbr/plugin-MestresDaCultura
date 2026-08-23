<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MDC\Services\FixedOpportunity;
use Monolog\Handler\TestHandler;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

class FixedOpportunityTest extends TestCase
{
    use OpportunityBuilder;
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

    private function capturedLog(): TestHandler
    {
        $handler = new TestHandler();
        $this->app->log->pushHandler($handler);

        return $handler;
    }

    function testReturnsNullWhenTheKeyIsAbsent()
    {
        $log = $this->capturedLog();

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
        $log = $this->capturedLog();

        $this->assertNull(
            $this->withConfiguredId(999999999)->get(),
            'Id inexistente devolve nulo, sem quebrar a aplicação'
        );

        $this->assertTrue(
            $log->hasErrorThatContains('999999999'),
            'O log precisa dizer qual id não foi encontrado'
        );
    }

    function testResolvesOnlyOncePerRequest()
    {
        $opportunity = $this->createOpportunity();
        $service = $this->withConfiguredId($opportunity->id);

        $service->get();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = 999999999;

        $this->assertEquals(
            $opportunity->id,
            $service->get()?->id,
            'A segunda chamada vem do cache de requisição, sem consultar de novo'
        );
    }

}
