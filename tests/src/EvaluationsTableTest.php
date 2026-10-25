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

// Lista de avaliações do programa na gestão: a data de envio de cada parecer; as outras oportunidades não aparecem
// no subsite.
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

    // Os textos falam de moderação, saem as colunas que o programa não usa e a data de envio vem depois do resultado.
    function testTheProgramColumnsAndTexts()
    {
        $headers = fn () => [
            ['text' => 'inscrição', 'value' => 'number', 'slug' => 'number'],
            ['text' => 'Agente coletivo', 'value' => 'coletivo', 'slug' => 'coletivo'],
            ['text' => 'Status das metas', 'value' => 'goalStatuses', 'slug' => 'goalStatuses'],
            ['text' => 'Faixa', 'value' => 'range', 'slug' => 'range'],
            ['text' => 'Nº avaliador', 'value' => 'valuer?.committeeSequentialNumber', 'slug' => 'committeeSequentialNumber'],
            ['text' => 'ID usuário avaliador', 'value' => 'valuer?.user', 'slug' => 'valuerUserId'],
            ['text' => 'ID agente avaliador', 'value' => 'valuer?.id', 'slug' => 'valuerAgentId'],
            ['text' => 'avaliador', 'value' => 'valuer?.name', 'slug' => 'evaluator'],
            ['text' => 'Resultado do avaliador', 'value' => 'evaluation?.resultString', 'slug' => 'result'],
            ['text' => 'Ações', 'value' => '', 'slug' => 'delete'],
        ];

        $all = $headers();
        EvaluationsTable::adjust($all, false);
        $this->assertSame(
            ['inscrição', 'Nº moderador', 'ID usuário do moderador', 'ID agente do moderador', 'Moderador', 'Resultado da moderação', 'Data de envio', 'Ações'],
            array_column($all, 'text'),
            'A lista de todas as avaliações, do gestor'
        );

        // Na lista do próprio moderador, saem as colunas sobre o moderador.
        $own = $headers();
        EvaluationsTable::adjust($own, true);
        $this->assertSame(['inscrição', 'Resultado da sua moderação', 'Data de envio', 'Ações'], array_column($own, 'text'), 'A lista do próprio moderador');
    }

    function testTheListShowsWhenEachEvaluationWasSent()
    {
        $phase = $this->evaluationPhaseWithASentEvaluation();

        [$row] = $this->listRows($phase) + [null];
        $this->assertSame('20/10/2026 14:35', $row['evaluation'][EvaluationsTable::SENT] ?? null);
    }

    function testOtherOpportunitiesListNothingInTheSubsite()
    {
        $this->evaluationPhaseWithASentEvaluation();
        $program = $this->app->config[FixedOpportunity::CONFIG_KEY];
        $phase = $this->evaluationPhaseWithASentEvaluation();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $program;

        // No subsite dos Mestres, nem o saasSuperAdmin vê as inscrições de outra oportunidade (Services\RegistrationScope).
        $this->assertSame([], $this->listRows($phase), 'A lista de outra oportunidade vem vazia');
    }
}
