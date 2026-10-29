<?php

namespace Tests\MDC;

use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MDC\Jobs\ReleaseStaleDrafts;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use MDC\Services\ModerationQueue;
use Opportunities\Jobs\RedistributeCommitteeRegistrations;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// O cadastro que está com um moderador que não vai analisá-lo volta para a fila da comissão: o desabilitado, o
// desabilitado que recebe a correção reenviada e o que deixou o rascunho parado. As avaliações enviadas não mudam.
class ModerationQueueTest extends TestCase
{
    use OpportunityBuilder;
    use RunsJobs;
    use UserDirector;

    private User $superAdmin;

    private int $registrationId;

    /** @var int[] */
    private array $registrationIds = [];

    /** @var EvaluationMethodConfigurationAgentRelation[] por nome */
    private array $moderators = [];

    private function createProgram(array $names, int $registrations = 1): void
    {
        $this->superAdmin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($this->superAdmin);

        $phase = $this->opportunityBuilder
            ->reset(owner: $this->superAdmin->profile, owner_entity: $this->superAdmin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: $registrations)
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration('Moderação', 1)
                ->save();

        foreach ($names as $name) {
            $this->moderators[$name] = $phase->addValuer('Moderação', $name)->getInstance();
        }

        $phase->redistributeCommitteeRegistrations();

        $fixed = $this->opportunityBuilder->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $this->registrationIds = array_map('intval', $this->app->conn->fetchFirstColumn('SELECT id FROM registration WHERE opportunity_id = :id ORDER BY id', ['id' => $fixed->id]));
        $this->registrationId = $this->registrationIds[0];
    }

    private function registration(?int $id = null): Registration
    {
        $this->app->em->clear();

        return $this->app->repo('Registration')->find($id ?? $this->registrationId);
    }

    /** @return int[] os moderadores com o cadastro */
    private function valuers(?int $id = null): array
    {
        return array_map('intval', array_keys((array) json_decode((string) $this->app->conn->fetchOne('SELECT valuers FROM registration WHERE id = :id', ['id' => $id ?? $this->registrationId]), true)));
    }

    private function holder(): EvaluationMethodConfigurationAgentRelation
    {
        [$user] = $this->valuers();

        return current(array_filter($this->moderators, fn ($relation) => $relation->agent->user->id === $user));
    }

    private function other(EvaluationMethodConfigurationAgentRelation $relation): EvaluationMethodConfigurationAgentRelation
    {
        return current(array_filter($this->moderators, fn ($candidate) => $candidate->id !== $relation->id));
    }

    private function evaluate(EvaluationMethodConfigurationAgentRelation $relation, int $status, ?int $registration = null): int
    {
        $this->app->disableAccessControl();
        $evaluation = new RegistrationEvaluation;
        $evaluation->registration = $this->registration($registration);
        $evaluation->user = $this->app->repo('User')->find($relation->agent->user->id);
        $evaluation->committee = 'Moderação';
        $evaluation->evaluationData = ['1' => ['label' => 'Campo', 'obsItems' => '', 'obs' => 'Não comprova.', 'evaluation' => 'invalid']];
        $evaluation->status = $status;
        $evaluation->save(true);
        $this->app->enableAccessControl();

        return $evaluation->id;
    }

    private function disable(EvaluationMethodConfigurationAgentRelation $relation): void
    {
        $this->login($this->app->repo('User')->find($this->superAdmin->id));
        $this->app->repo('EvaluationMethodConfigurationAgentRelation')->find($relation->id)->disable();
        $this->runJobs();
    }

    // O rascunho sem mudança há `$days` dias, e a devolução diária.
    private function idleFor(int $evaluation, int $days): void
    {
        $this->app->conn->executeStatement("UPDATE registration_evaluation SET update_timestamp = now() - make_interval(days => :days) WHERE id = :id", ['days' => $days, 'id' => $evaluation]);
        (new ModerationQueue)->releaseStaleDrafts();
        $this->runJobs();
    }

    /** Uma opção da fila na configuração da fase, como o gestor grava na tela. */
    private function configure(string $option, mixed $value): void
    {
        $configuration = $this->registration()->evaluationMethodConfiguration;
        $configuration->$option = $value;
        $this->app->disableAccessControl();
        $configuration->save(true);
        $this->app->enableAccessControl();
    }

    private function exists(int $evaluation): bool
    {
        return (bool) $this->app->conn->fetchOne('SELECT count(*) FROM registration_evaluation WHERE id = :id', ['id' => $evaluation]);
    }

    function testDisablingAModeratorPassesHisDraftToAnother()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);

        $this->disable($holder);

        $this->assertFalse($this->exists($draft), 'O rascunho do desabilitado é apagado');
        $this->assertSame([$this->other($holder)->agent->user->id], $this->valuers(), 'E o cadastro passa ao outro moderador');
    }

    // Sem avaliação começada, o cadastro passa na hora a outro moderador.
    function testDisablingAModeratorPassesHisPendingRegistrationsRightAway()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();

        $this->disable($holder);

        $this->assertSame([$this->other($holder)->agent->user->id], $this->valuers(), 'O cadastro pendente passa ao outro moderador');
    }

    function testSentEvaluationsAreNotTouched()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $sent = $this->evaluate($holder, RegistrationEvaluation::STATUS_SENT);

        $this->disable($holder);

        $this->assertTrue($this->exists($sent), 'A avaliação enviada fica');
        $this->assertSame([$holder->agent->user->id], $this->valuers(), 'E o cadastro segue com quem a enviou');
    }

    // Só o rascunho volta para a fila; a concluída, mesmo sem envio, fica com quem a concluiu.
    function testAConcludedEvaluationStays()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $concluded = $this->evaluate($holder, RegistrationEvaluation::STATUS_EVALUATED);

        $this->disable($holder);

        $this->assertTrue($this->exists($concluded), 'A concluída fica');
    }

    // "Substituir avaliador" fica com o core, sem redistribuição.
    function testReplacingAModeratorIsLeftToTheCore()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $concluded = $this->evaluate($holder, RegistrationEvaluation::STATUS_EVALUATED);
        $substitute = $this->userDirector->createUser();

        $jobs = fn () => $this->app->conn->fetchAllAssociative("SELECT id, next_execution_timestamp FROM job WHERE name = 'RedistribRegs' ORDER BY id");
        $before = $jobs();

        $this->login($this->app->repo('User')->find($this->superAdmin->id));
        $this->app->repo('EvaluationMethodConfigurationAgentRelation')->find($holder->id)->replaceEvaluator($substitute);

        $this->assertTrue($this->exists($concluded), 'A concluída do substituído fica');
        $this->assertSame($before, $jobs(), 'E a substituição não agenda redistribuição');
    }

    // Desabilitado pela substituição, o rascunho fica com o core.
    function testADisableMarkedByTheReplacementKeepsTheDrafts()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);

        $this->login($this->app->repo('User')->find($this->superAdmin->id));
        $relation = $this->app->repo('EvaluationMethodConfigurationAgentRelation')->find($holder->id);
        $relation->__skipRedistribution = true;
        $relation->disable();

        $this->assertTrue($this->exists($draft), 'O rascunho fica com o core');
    }

    // Com o moderador duas vezes na comissão, desabilitado numa relação e ativo pela outra, o rascunho fica.
    function testWithAnotherEnabledRelationTheDraftsStay()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);
        $this->app->conn->executeStatement('INSERT INTO agent_relation (agent_id, object_type, object_id, type, has_control, create_timestamp, status) SELECT agent_id, object_type, object_id, type, has_control, now(), status FROM agent_relation WHERE id = :id', ['id' => $holder->id]);
        $this->app->em->clear();

        $this->disable($holder);

        $this->assertTrue($this->exists($draft), 'O rascunho fica com quem segue ativo');
    }

    function testWithManualDistributionTheDraftsStay()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $configuration = $this->registration()->evaluationMethodConfiguration;
        $configuration->distributionConfiguration = 'deactivate';
        $this->app->disableAccessControl();
        $configuration->save(true);
        $this->app->enableAccessControl();
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);

        $this->disable($holder);

        $this->assertTrue($this->exists($draft), 'Com a distribuição manual, o rascunho fica para o gestor resolver');
    }

    function testAResentCorrectionOfADisabledModeratorGoesToAnother()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $holder = $this->holder();
        $sent = $this->evaluate($holder, RegistrationEvaluation::STATUS_SENT);
        $this->disable($holder);

        (new Moderation)->sendBackToValuers($this->registration());
        $this->runJobs();

        $this->assertFalse($this->exists($sent), 'A avaliação do desabilitado não volta a ele');
        $this->assertSame([$this->other($holder)->agent->user->id], $this->valuers(), 'A correção vai para o outro moderador');
    }

    // O rascunho parado sai e a redistribuição da comissão decide para quem vai o cadastro.
    function testAStaleDraftGoesBackToTheQueue()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $draft = $this->evaluate($this->holder(), RegistrationEvaluation::STATUS_DRAFT);

        $this->idleFor($draft, 6);
        $this->assertTrue($this->exists($draft), 'Com 6 dias, o rascunho fica');

        $this->idleFor($draft, 8);
        $this->assertFalse($this->exists($draft), 'Com mais de 7 dias, volta para a fila');

        $active = array_map(fn ($relation) => $relation->agent->user->id, $this->moderators);
        $this->assertCount(1, array_intersect($this->valuers(), $active), 'E a redistribuição o dá a um moderador ativo');
    }

    function testAStaleDraftWithManualDistributionStays()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $this->configure('distributionConfiguration', 'deactivate');
        $draft = $this->evaluate($this->holder(), RegistrationEvaluation::STATUS_DRAFT);

        $this->idleFor($draft, 8);
        $this->assertTrue($this->exists($draft), 'Com a distribuição manual, fica para o gestor');

        // Nem quando o gestor distribui na mão ("Distribuir avaliações agora").
        $this->app->enqueueOrReplaceJob(RedistributeCommitteeRegistrations::SLUG, ['evaluationMethodConfiguration' => $this->registration()->evaluationMethodConfiguration], 'now');
        $this->runJobs();
        $this->assertTrue($this->exists($draft), 'Nem na distribuição feita pelo gestor');
    }

    // Desabilitado com a opção desligada, os rascunhos ficam; ligada depois, a próxima redistribuição os libera.
    function testDraftsKeptWhenTheModeratorWasDisabledAreReleasedByALaterRedistribution()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $this->configure(ModerationQueue::RELEASE_DISABLED, false);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);
        $this->disable($holder);
        $this->assertTrue($this->exists($draft));

        $this->configure(ModerationQueue::RELEASE_DISABLED, true);
        $this->app->enqueueOrReplaceJob(RedistributeCommitteeRegistrations::SLUG, ['evaluationMethodConfiguration' => $this->registration()->evaluationMethodConfiguration], 'now');
        $this->runJobs();

        $this->assertFalse($this->exists($draft), 'A redistribuição seguinte libera o rascunho do desabilitado');
        $this->assertSame([$this->other($holder)->agent->user->id], $this->valuers());
    }

    function testAStaleDraftOfTheOnlyModeratorStaysWithHim()
    {
        $this->createProgram(['Moderadora A']);
        $holder = $this->holder();

        $this->idleFor($draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT), 8);

        $this->assertFalse($this->exists($draft));
        $this->assertSame([$holder->agent->user->id], $this->valuers(), 'Sem outro moderador, o cadastro volta para ele');
    }

    function testADraftWithoutAnUpdateDateCountsFromItsCreation()
    {
        $this->createProgram(['Moderadora A']);
        $draft = $this->evaluate($this->holder(), RegistrationEvaluation::STATUS_DRAFT);
        $this->app->conn->executeStatement("UPDATE registration_evaluation SET update_timestamp = NULL, create_timestamp = now() - interval '8 days' WHERE id = :id", ['id' => $draft]);

        (new ModerationQueue)->releaseStaleDrafts();
        $this->runJobs();

        $this->assertFalse($this->exists($draft), 'Sem data de alteração, conta da criação');
    }

    function testACommitteeWithoutActiveModeratorsKeepsTheRegistrationWaiting()
    {
        $this->createProgram(['Moderadora A']);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);

        $this->disable($holder);

        $this->assertFalse($this->exists($draft));
        $this->assertSame([], $this->valuers(), 'O cadastro fica na fila, sem moderador');
        $this->assertSame(Registration::STATUS_SENT, $this->registration()->status, 'E segue em análise');
    }

    // O job é diário, e agendar de novo não cria um segundo.
    function testTheStaleDraftsJobRunsDaily()
    {
        ReleaseStaleDrafts::schedule();
        ReleaseStaleDrafts::schedule();

        $jobs = $this->app->conn->fetchAllAssociative('SELECT interval_string, iterations FROM job WHERE name = :name', ['name' => ReleaseStaleDrafts::SLUG]);

        $this->assertSame([['interval_string' => '+1 day', 'iterations' => ReleaseStaleDrafts::ITERATIONS]], $jobs);
    }

    // As duas regras são opções da fase e vêm ligadas, com 7 dias.
    function testTheOptionsStartOnWithSevenDays()
    {
        $this->createProgram(['Moderadora A']);
        $configuration = $this->registration()->evaluationMethodConfiguration;

        $this->assertTrue((bool) $configuration->{ModerationQueue::RELEASE_DISABLED});
        $this->assertTrue((bool) $configuration->{ModerationQueue::RELEASE_STALE});
        $this->assertSame(7, (int) $configuration->{ModerationQueue::STALE_DAYS});
    }

    function testWithTheDisabledOptionOffTheDraftsStay()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $this->configure(ModerationQueue::RELEASE_DISABLED, false);
        $holder = $this->holder();
        $draft = $this->evaluate($holder, RegistrationEvaluation::STATUS_DRAFT);

        $this->disable($holder);

        $this->assertTrue($this->exists($draft), 'Com a opção desligada, o rascunho fica');
    }

    function testWithTheStaleOptionOffNothingIsReleased()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $this->configure(ModerationQueue::RELEASE_STALE, false);
        $draft = $this->evaluate($this->holder(), RegistrationEvaluation::STATUS_DRAFT);

        $this->idleFor($draft, 30);

        $this->assertTrue($this->exists($draft), 'Com a opção desligada, o rascunho parado fica');
    }

    function testTheStaleDaysComeFromTheConfiguration()
    {
        $this->createProgram(['Moderadora A', 'Moderadora B']);
        $this->configure(ModerationQueue::STALE_DAYS, 3);
        $draft = $this->evaluate($this->holder(), RegistrationEvaluation::STATUS_DRAFT);

        $this->idleFor($draft, 2);
        $this->assertTrue($this->exists($draft), 'Com 2 dias, fica');

        $this->idleFor($draft, 4);
        $this->assertFalse($this->exists($draft), 'Com 4 dias, passa dos 3 configurados');
    }
}
