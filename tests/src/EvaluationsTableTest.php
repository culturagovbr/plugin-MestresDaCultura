<?php

namespace Tests\MDC;

use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationEvaluation;
use MDC\Services\EvaluationsTable;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Lista de avaliações do programa na gestão: a data de envio de cada parecer, como no Cultura Viva
// (themes/CulturaViva/Theme.php). As outras oportunidades não mudam.
class EvaluationsTableTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    /** A fase de avaliação, com um cadastro distribuído a um moderador e o parecer dele enviado em 20/10/2026 14:35. */
    private function evaluationPhaseWithASentEvaluation(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        $phase = $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: 1)
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration('Moderação', 1)
                ->save();
        $moderator = $phase->addValuer('Moderação', 'Moderadora')->getInstance();
        $phase->redistributeCommitteeRegistrations();
        $evaluationPhase = $phase->getInstance()->opportunity;
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunityBuilder->getInstance()->id;

        $this->app->disableAccessControl();
        $evaluation = new RegistrationEvaluation;
        $evaluation->registration = $this->app->repo('Registration')->findOneBy(['opportunity' => $evaluationPhase]);
        $evaluation->user = $this->app->repo('User')->find($moderator->agent->user->id);
        $evaluation->committee = 'Moderação';
        $evaluation->evaluationData = ['1' => ['label' => 'Campo', 'obsItems' => '', 'obs' => 'Não comprova.', 'evaluation' => 'invalid']];
        $evaluation->status = RegistrationEvaluation::STATUS_SENT;
        $evaluation->save(true);
        $this->app->enableAccessControl();
        $this->app->conn->executeStatement("UPDATE registration_evaluation SET sent_timestamp = '2026-10-20 14:35:00' WHERE id = :id", ['id' => $evaluation->id]);
        $this->login($manager);

        return $this->app->repo('Opportunity')->find($evaluationPhase->id);
    }

    /** @return array[] as linhas da lista, como a tela pede */
    private function listRows(Opportunity $phase): array
    {
        $user = $this->app->user;
        $this->app->em->clear();
        $this->app->reset();
        $this->login($this->app->repo('User')->find($user->id));
        $this->app->run(new ServerRequest(method: 'GET', uri: '/api/opportunity/findEvaluations', queryParams: [
            '@opportunity' => $phase->id,
            '@select' => 'id,number',
        ]), false);

        return json_decode((string) $this->app->response->getBody(), true) ?: [];
    }

    function testTheColumnComesAfterTheModeratorResult()
    {
        $headers = [
            ['text' => 'inscrição', 'value' => 'number', 'slug' => 'number'],
            ['text' => 'Resultado do avaliador', 'value' => 'evaluation?.resultString', 'slug' => 'result'],
            ['text' => 'Ações', 'value' => '', 'slug' => 'delete'],
        ];

        EvaluationsTable::addColumn($headers);

        $this->assertSame(['inscrição', 'Resultado do avaliador', 'Data de envio', 'Ações'], array_column($headers, 'text'));
    }

    function testTheListShowsWhenEachEvaluationWasSent()
    {
        $phase = $this->evaluationPhaseWithASentEvaluation();

        [$row] = $this->listRows($phase) + [null];
        $this->assertSame('20/10/2026 14:35', $row['evaluation'][EvaluationsTable::SENT] ?? null);
    }

    function testOtherOpportunitiesFollowTheCore()
    {
        $this->evaluationPhaseWithASentEvaluation();
        $program = $this->app->config[FixedOpportunity::CONFIG_KEY];
        $phase = $this->evaluationPhaseWithASentEvaluation();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $program;

        [$row] = $this->listRows($phase) + [null];
        $this->assertNotNull($row, 'A lista traz o parecer');
        $this->assertArrayNotHasKey(EvaluationsTable::SENT, $row['evaluation'] ?? []);
    }
}
