<?php

namespace Tests\MDC;

use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// A mesma pessoa não entra duas vezes na comissão do programa, habilitada, desabilitada ou com o convite pendente.
class CommitteeMembershipTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private User $superAdmin;

    private function createOpportunity(): Opportunity
    {
        $this->superAdmin ??= $this->userDirector->createUser('saasSuperAdmin');
        $this->login($this->superAdmin);

        return $this->opportunityBuilder
            ->reset(owner: $this->superAdmin->profile, owner_entity: $this->superAdmin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->done()
            ->refresh()
            ->getInstance();
    }

    private function members(EvaluationMethodConfiguration $committee, string $group): int
    {
        return (int) $this->app->conn->fetchOne('SELECT count(*) FROM agent_relation WHERE object_type = :type AND object_id = :id AND type = :group', [
            'type' => EvaluationMethodConfiguration::class, 'id' => $committee->id, 'group' => $group,
        ]);
    }

    private function include(EvaluationMethodConfiguration $committee, User $moderator, string $group = 'Moderação'): bool
    {
        $this->login($this->superAdmin);

        try {
            $committee->refreshed()->createAgentRelation($this->app->repo('Agent')->find($moderator->profile->id), $group, true);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    function testTheSamePersonIsNotIncludedTwiceInTheProgramCommittee()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $committee = $fixed->evaluationMethodConfiguration;
        $moderator = $this->userDirector->createUser();

        $this->assertTrue($this->include($committee, $moderator));
        $this->assertFalse($this->include($committee, $moderator), 'A segunda inclusão é recusada');

        $this->app->repo('EvaluationMethodConfigurationAgentRelation')->findOneBy(['owner' => $committee, 'agent' => $moderator->profile])->disable();
        $this->assertFalse($this->include($committee, $moderator), 'Também com a pessoa desabilitada');

        // Põe a pessoa com o convite pendente.
        $this->app->conn->executeStatement("UPDATE agent_relation SET status = -5 WHERE object_id = :id AND type = 'Moderação'", ['id' => $committee->id]);
        $this->app->em->clear();
        $this->assertFalse($this->include($committee, $moderator), 'E com o convite ainda pendente');

        $this->assertSame(1, $this->members($committee, 'Moderação'));
        $this->assertTrue($this->include($committee, $moderator, 'Outra comissão'), 'Em outra comissão da fase, pode');
    }

    function testOtherOpportunitiesFollowTheCore()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $moderator = $this->userDirector->createUser();

        $this->assertTrue($this->include($other->evaluationMethodConfiguration, $moderator));
        $this->assertTrue($this->include($other->evaluationMethodConfiguration, $moderator), 'Fora do programa, o core aceita');
    }
}
