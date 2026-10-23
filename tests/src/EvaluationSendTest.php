<?php

namespace Tests\MDC;

use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Dois envios da mesma avaliação do programa (clique duplo, duas abas) fazem um envio só: o segundo é recusado antes
// de a devolução, o e-mail e a notificação saírem de novo.
class EvaluationSendTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private int $evaluationId;

    private User $moderator;

    private int $sends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdmin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($superAdmin);

        $valuer = $this->opportunityBuilder
            ->reset(owner: $superAdmin->profile, owner_entity: $superAdmin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: 1)
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration('Moderação', 1)
                ->save()
                ->addValuer('Moderação', 'Moderadora')->done()
                ->redistributeCommitteeRegistrations()
                ->withValuer('Moderação', 'Moderadora')
                    ->createConcludedEvaluation();

        $this->moderator = $valuer->getInstance()->agent->user;
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunityBuilder->getInstance()->id;
        $this->evaluationId = $this->app->repo('RegistrationEvaluation')->findOneBy(['user' => $this->moderator])->id;

        // Conta os envios pelo `send:after`, de onde saem a devolução, o e-mail e a notificação.
        $sends = &$this->sends;
        $this->app->hook('entity(RegistrationEvaluation).send:after', function () use (&$sends) {
            $sends++;
        });
    }

    private function evaluation(): RegistrationEvaluation
    {
        $this->app->em->clear();
        $this->login($this->app->repo('User')->find($this->moderator->id));

        return $this->app->repo('RegistrationEvaluation')->find($this->evaluationId);
    }

    // Envia sem o controle de acesso, para conferir só a trava do programa.
    private function send(RegistrationEvaluation $evaluation): void
    {
        $this->app->disableAccessControl();

        try {
            $evaluation->send(true);
        } finally {
            $this->app->enableAccessControl();
        }
    }

    /** O envio é recusado pela trava do programa, e não por outra permissão do core. */
    private function assertRefusedAsAlreadySent(RegistrationEvaluation $evaluation, string $message): void
    {
        try {
            $this->send($evaluation);
            $this->fail($message);
        } catch (PermissionDenied $e) {
            $this->assertStringContainsString('já foi enviada', $e->getMessage(), $message);
        }
    }

    private function transactionLevel(): int
    {
        return $this->app->em->getConnection()->getTransactionNestingLevel();
    }

    private function storedStatus(): int
    {
        return (int) $this->app->conn->fetchOne('SELECT status FROM registration_evaluation WHERE id = :id', ['id' => $this->evaluationId]);
    }

    function testTheSecondSendOfTheSameEvaluationIsRefused()
    {
        $level = $this->transactionLevel();
        $this->send($this->evaluation());
        $this->assertSame(1, $this->sends, 'O primeiro envio segue como no core');
        $this->assertSame($level, $this->transactionLevel(), 'A transação da trava é fechada depois do envio');
        $this->assertSame(RegistrationEvaluation::STATUS_SENT, $this->storedStatus());

        $this->assertRefusedAsAlreadySent($this->evaluation(), 'O segundo envio é recusado');
        $this->assertSame(1, $this->sends, 'Sem uma segunda devolução, e-mail ou notificação');
        $this->assertSame($level, $this->transactionLevel(), 'E também depois da recusa');
    }

    // O envio relê o status com a linha travada.
    function testASendThatLoadedTheEvaluationBeforeAnotherFinishedIsRefused()
    {
        $evaluation = $this->evaluation();
        $this->app->conn->executeStatement('UPDATE registration_evaluation SET status = :sent, sent_timestamp = now() WHERE id = :id', ['sent' => RegistrationEvaluation::STATUS_SENT, 'id' => $this->evaluationId]);

        $this->assertRefusedAsAlreadySent($evaluation, 'O envio que chegou depois é recusado');
        $this->assertSame(0, $this->sends);
    }
}
