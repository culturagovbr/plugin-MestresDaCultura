<?php

namespace Tests\MDC;

use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// No subsite, a API de inscrições só alcança a oportunidade do programa e as fases dela.
class RegistrationScopeTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

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

        $builder->addDataCollectionPhase()
            ->setRegistrationPeriod(new Open)
            ->save()
            ->done();

        return $builder->refresh()->getInstance();
    }

    private function registrationOf(User $user, Opportunity $opportunity): Registration
    {
        $this->login($user);

        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $user->profile;
        $registration->save(true);

        return $registration;
    }

    /** @return int[] */
    private function idsFound(User $user, array $params = []): array
    {
        $this->login($user);
        // Consulta como o painel: com rascunho, pela permissão de dono.
        $result = (new ApiQuery(Registration::class, ['@select' => 'id', '@permissions' => '@control', 'status' => API::GTE(0), ...$params]))->find();

        return array_map(fn ($registration) => $registration['id'], $result);
    }

    function testTheApiBringsOnlyTheFixedOpportunityRegistrationsToWhoIsNotAdmin()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $master = $this->userDirector->createUser();
        $inFixed = $this->registrationOf($master, $fixed);
        $inOther = $this->registrationOf($master, $other);

        $this->assertSame([$inFixed->id], $this->idsFound($master), 'A mestra só vê o cadastro do programa');
        $this->assertSame([], $this->idsFound($master, ['opportunity' => API::EQ($other->id)]), 'Pedir outra oportunidade não devolve nada');
        $this->assertSame([$inFixed->id], $this->idsFound($master, ['opportunity' => API::IN([$fixed->id, $other->id])]), 'Da lista pedida, só sobra o programa');
        $this->assertNotContains($inOther->id, $this->idsFound($master, ['id' => API::EQ($inOther->id)]));
    }

    function testAPhaseRegistrationIsStillFound()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $phase = current(array_filter($fixed->allPhases, fn ($phase) => $phase->id !== $fixed->id));
        $master = $this->userDirector->createUser();
        $inPhase = $this->registrationOf($master, $phase);

        $this->assertSame([$inPhase->id], $this->idsFound($master, ['opportunity' => API::EQ($phase->id)]), 'As fases do programa seguem na consulta');
    }

    function testAdminKeepsSeeingEveryRegistration()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $master = $this->userDirector->createUser();
        $inFixed = $this->registrationOf($master, $fixed);
        $inOther = $this->registrationOf($master, $other);

        // Os números de inscrição são sorteados: compara sem a ordem.
        $found = $this->idsFound($this->userDirector->createUser('admin'), ['id' => API::IN([$inFixed->id, $inOther->id])]);
        $expected = [$inFixed->id, $inOther->id];
        sort($found);
        sort($expected);

        $this->assertSame($expected, $found, 'O admin segue vendo tudo, como no core');
    }
}
