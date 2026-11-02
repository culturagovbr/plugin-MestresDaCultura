<?php

namespace Tests\MDC;

use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// As atualizações de dados que dependem da oportunidade do programa ficam pendentes enquanto ela não existe.
class DbUpdatesTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use UserDirector;

    private const NEED_THE_PROGRAM = [
        'mdc: publica os dados dos mestres já aprovados',
        'mdc: o festejo do cadastro dos Mestres passa a ser escolhido no Calendário',
    ];

    private function updates(): array
    {
        return include dirname((new \ReflectionClass(\MDC\Plugin::class))->getFileName()) . '/db-updates.php';
    }

    function testTheUpdatesThatNeedTheProgramWaitForIt()
    {
        $this->captureLog();
        $updates = $this->updates();

        $this->app->config[FixedOpportunity::CONFIG_KEY] = null;

        foreach (self::NEED_THE_PROGRAM as $name) {
            $this->assertFalse($updates[$name](), "Sem a oportunidade do programa, \"{$name}\" fica para o próximo deploy");
        }

        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance()
            ->id;

        foreach (self::NEED_THE_PROGRAM as $name) {
            $this->assertNotFalse($updates[$name](), "Com a oportunidade do programa, \"{$name}\" é marcada como aplicada");
        }
    }
}
