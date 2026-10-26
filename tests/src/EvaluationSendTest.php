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
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Dois envios da mesma avaliação do programa fazem um envio só, e nem um rascunho atrasado nem o formulário de uma aba
// antiga mudam o parecer enviado.
class EvaluationSendTest extends TestCase
{
    use OpportunityBuilder;
    use RequestFactory;
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

    // Um rascunho salvo depois do envio não tira o parecer de enviado.
    function testALateDraftSaveDoesNotUndoTheSend()
    {
        $stale = $this->evaluation();
        $this->app->conn->executeStatement('UPDATE registration_evaluation SET status = :sent, sent_timestamp = now() WHERE id = :id', ['sent' => RegistrationEvaluation::STATUS_SENT, 'id' => $this->evaluationId]);

        $stale->status = RegistrationEvaluation::STATUS_DRAFT;
        try {
            $this->app->disableAccessControl();
            $stale->save(true);
            $this->fail('O rascunho atrasado é recusado');
        } catch (PermissionDenied $e) {
            $this->assertStringContainsString('já foi enviada', $e->getMessage());
        } finally {
            $this->app->enableAccessControl();
        }

        $this->assertSame(RegistrationEvaluation::STATUS_SENT, $this->storedStatus(), 'O parecer segue enviado');
    }

    // Reabrir o parecer enviado (pelo gestor ou no reenvio da correção) segue permitido.
    function testReopeningASentEvaluationStillWorks()
    {
        $this->send($this->evaluation());

        $evaluation = $this->evaluation();
        $evaluation->status = RegistrationEvaluation::STATUS_DRAFT;
        $this->app->disableAccessControl();
        $evaluation->save(true);
        $this->app->enableAccessControl();

        $this->assertSame(RegistrationEvaluation::STATUS_DRAFT, $this->storedStatus());
    }

    // No envio em lote, o parecer já enviado por outra aba é pulado e o lote não para no meio.
    function testTheBatchSendSkipsOneAlreadySentInAnotherTab()
    {
        $registration = (int) $this->app->conn->fetchOne('SELECT registration_id FROM registration_evaluation WHERE id = :id', ['id' => $this->evaluationId]);
        $second = (int) $this->app->conn->fetchOne("INSERT INTO registration (id, opportunity_id, agent_id, status, create_timestamp, number) SELECT nextval('registration_id_seq'), opportunity_id, agent_id, status, now(), 'x' || nextval('registration_id_seq') FROM registration WHERE id = :id RETURNING id", ['id' => $registration]);
        $other = (int) $this->app->conn->fetchOne("INSERT INTO registration_evaluation (id, registration_id, user_id, result, evaluation_data, status, committee) SELECT nextval('registration_evaluation_id_seq'), :second, user_id, result, evaluation_data, status, committee FROM registration_evaluation WHERE id = :id RETURNING id", ['second' => $second, 'id' => $this->evaluationId]);

        // Os dois carregados como concluídos; um deles é enviado antes de o lote começar.
        $opportunity = $this->evaluation()->registration->opportunity;
        $this->app->repo('RegistrationEvaluation')->find($other);
        $this->app->conn->executeStatement('UPDATE registration_evaluation SET status = :sent, sent_timestamp = now() WHERE id = :id', ['sent' => RegistrationEvaluation::STATUS_SENT, 'id' => $other]);

        $this->app->disableAccessControl();
        try {
            $opportunity->sendUserEvaluations($this->app->user);
        } finally {
            $this->app->enableAccessControl();
        }

        $this->assertSame(1, $this->sends, 'Só o que ainda não tinha sido enviado sai, sem parar o lote');
        $this->assertSame(RegistrationEvaluation::STATUS_SENT, $this->storedStatus());
    }

    // Uma falha no meio da gravação não deixa transação aberta.
    function testAFailureInTheMiddleLeavesNoTransactionOpen()
    {
        $level = $this->transactionLevel();
        $this->app->hook('entity(RegistrationEvaluation).save:after', function () {
            throw new \RuntimeException('falha simulada');
        });

        try {
            $this->send($this->evaluation());
        } catch (\RuntimeException $e) {
        }

        $this->assertSame($level, $this->transactionLevel());
    }

    // A gravação pelo formulário de um parecer já enviado é recusada, mesmo com a permissão de avaliar em cache.
    function testTheFormDoesNotChangeASentEvaluation()
    {
        $this->send($this->evaluation());
        $registration = $this->evaluation()->registration;

        // Mantém a permissão de avaliar de antes do envio, como no cache.
        $moderator = $this->moderator->id;
        $this->app->hook('can(Registration.evaluate)', function ($user, &$result) use ($moderator) {
            if ($user->id === $moderator) {
                $result = true;
            }
        });

        $stored = fn () => $this->app->conn->fetchAssociative('SELECT status, evaluation_data FROM registration_evaluation WHERE id = :id', ['id' => $this->evaluationId]);
        $before = $stored();
        $data = ['data' => ['1' => ['label' => 'Campo', 'obsItems' => '', 'obs' => 'Mudado depois do envio.', 'evaluation' => 'valid']]];

        // Salvar, Concluir e um pedido com um `uid` que não existe.
        foreach ([[[$registration->id], $data], [[$registration->id, 'status' => 'evaluated'], $data], [[$registration->id], $data + ['uid' => 999999]]] as [$params, $payload]) {
            $this->login($this->app->repo('User')->find($this->moderator->id));
            $this->app->reset();
            $this->app->run($this->requestFactory->POST('registration', 'saveEvaluation', $params, $payload), false);

            $this->assertSame(403, $this->app->response->getStatusCode(), 'A gravação é recusada');
            $this->assertStringContainsString('j\u00e1 foi enviada', (string) $this->app->response->getBody());
            $this->assertSame($before, $stored(), 'O parecer segue enviado, com os mesmos dados');
        }
    }
}
