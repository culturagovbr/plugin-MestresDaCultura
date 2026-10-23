<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MDC\Services\FixedOpportunity;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;

/**
 * Cria a oportunidade do programa, coleta de dados com a moderação documental na raiz, configurada como a oportunidade
 * fixa. Requer o OpportunityBuilder e o UserDirector.
 */
trait ProgramOpportunity
{
    private function createProgramOpportunity(): Opportunity
    {
        $superAdmin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($superAdmin);

        $this->opportunityBuilder
            ->reset(owner: $superAdmin->profile, owner_entity: $superAdmin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->done();

        $fixed = $this->opportunityBuilder->refresh()->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;

        return $fixed;
    }
}
