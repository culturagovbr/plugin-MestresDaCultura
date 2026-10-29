<?php

namespace Tests\MDC;

use MDC\Services\FestivitySeal;
use MDC\Services\FixedOpportunity;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

/**
 * Os serviços do plugin releem a entidade configurada depois de o Doctrine ser limpo, como no "Aplicar resultado" em
 * lote da avaliação.
 */
class ProgramSealTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    function testTheSealIsReadAgainAfterTheEntityManagerIsCleared()
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);
        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
        $this->app->config[FestivitySeal::CONFIG_KEY] = $seal->id;

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: 2)
            ->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $ids = $this->app->conn->fetchFirstColumn('SELECT id FROM registration WHERE opportunity_id = ? ORDER BY id', [$opportunity->id]);

        $programSeal = new ProgramSeal;
        $festivitySeal = new FestivitySeal;
        $fixed = new FixedOpportunity;

        $programSeal->apply($this->app->repo('Registration')->find($ids[0]));
        $festivitySeal->get();
        $fixed->get();

        $this->app->em->clear();

        $programSeal->apply($this->app->repo('Registration')->find($ids[1]));
        $this->assertSame(2, (int) $this->app->conn->fetchOne('SELECT count(*) FROM seal_relation WHERE seal_id = ?', [$seal->id]), 'A segunda aprovação também ganha o selo');

        $this->assertTrue($this->app->em->contains($festivitySeal->get()), 'O selo dos festejos também é relido');
        $this->assertTrue($this->app->em->contains($fixed->get()), 'E a oportunidade do programa');
    }
}
