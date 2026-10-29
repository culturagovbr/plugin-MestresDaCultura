<?php

namespace Tests\MDC;

use Laminas\Diactoros\ServerRequest;
use MailNotification\JobTypes\SendMailNotification;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * A moderação de ponta a ponta, pelo envio real do parecer: "não ok" devolve o cadastro à mestra com os campos
 * recusados reabertos, e "tudo ok" aprova com o selo e o certificado.
 */
class ModerationFlowTest extends TestCase
{
    use CapturesLog;
    use RunsJobs;
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    private int $registrationId;
    private int $evaluationId;
    private User $moderator;
    private string $document;
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureLog();

        $superAdmin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($superAdmin);

        $valuer = $this->opportunityBuilder
            ->reset(owner: $superAdmin->profile, owner_entity: $superAdmin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('Dados')
                ->createField('documento', 'text', title: 'Documento')
                ->createField('nome', 'text', title: 'Nome artístico')
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: 1)
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setAutoApplicationAllowed(true)
                ->setCommitteeValuersPerRegistration('Moderação', 1)
                ->save()
                ->addValuer('Moderação', 'Moderadora')->done()
                ->redistributeCommitteeRegistrations()
                ->withValuer('Moderação', 'Moderadora')
                    ->createDraftEvaluation();

        $opportunity = $this->opportunityBuilder->getInstance();
        $this->document = $this->opportunityBuilder->getFieldName('documento');
        $this->name = $this->opportunityBuilder->getFieldName('nome');

        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $this->moderator = $valuer->getInstance()->agent->user;
        $evaluation = $this->app->repo('RegistrationEvaluation')->findOneBy(['user' => $this->moderator]);
        $this->evaluationId = $evaluation->id;
        $this->registrationId = $evaluation->registration->id;

        // O moderador vê os dois campos, gravados direto no banco.
        $this->app->conn->executeStatement('UPDATE opportunity SET avaliable_evaluation_fields = :fields WHERE id = :id', [
            'fields' => json_encode([$this->document => 'true', $this->name => 'true']),
            'id' => $opportunity->id,
        ]);
    }

    /** O parecer da moderadora: campo => 'valid' | 'invalid', pelo envio do core. */
    private function moderate(array $results): void
    {
        $this->app->em->clear();
        $this->login($this->app->repo('User')->find($this->moderator->id));
        $evaluation = $this->app->repo('RegistrationEvaluation')->find($this->evaluationId);

        $data = [];
        foreach ($results as $field => $result) {
            $data[substr($field, strlen('field_'))] = ['label' => $field === $this->document ? 'Documento' : 'Nome artístico', 'evaluation' => $result, 'obs' => $result === 'invalid' ? 'Não comprova.' : ''];
        }

        $this->app->disableAccessControl();
        try {
            $evaluation->status = RegistrationEvaluation::STATUS_DRAFT;
            $evaluation->evaluationData = $data;
            $evaluation->save(true);
            $evaluation->send(true);
        } finally {
            $this->app->enableAccessControl();
        }
    }

    private function registration(): Registration
    {
        $this->app->em->clear();

        return $this->app->repo('Registration')->find($this->registrationId);
    }

    private function jobs(string $name, string $like = '%'): array
    {
        return $this->app->conn->fetchFirstColumn('SELECT metadata::text FROM job WHERE name = :name AND metadata::text LIKE :like', ['name' => $name, 'like' => $like]);
    }

    function testANotOkSendsTheRegistrationBackToTheMaster()
    {
        $this->moderate([$this->document => 'invalid', $this->name => 'valid']);
        $registration = $this->registration();

        $this->assertSame(Registration::STATUS_SENT, $registration->status, 'Pendente, e não inválida');
        $this->assertSame([$this->document], (array) $registration->editableFields, 'Só o campo recusado reabre');
        $this->assertSame(Moderation::NO_DEADLINE, $registration->editableUntil?->format('Y-m-d H:i:s'), 'Sem prazo');
        $this->assertNull($registration->editSentTimestamp);
        $this->assertTrue(Moderation::isPending($registration));

        $this->assertSame([], $this->jobs('SyncPhaseRegistrations'), 'O status não muda de fato: nada a copiar para a fase seguinte');
        $returned = $this->jobs(SendMailNotification::SLUG, '%' . Moderation::MAIL_RETURNED . '%');
        $this->assertCount(1, $returned, 'Um e-mail de pendência');
        $this->assertSame([['name' => $this->document, 'label' => 'Documento', 'obs' => 'Não comprova.']], json_decode($returned[0], true)['params']['campos'] ?? null, 'Com o campo e o motivo');
        $this->assertSame(1, (int) $this->app->conn->fetchOne('SELECT count(*) FROM notification WHERE user_id = :user', ['user' => $registration->owner->user->id]), 'E o aviso no sino da dona');
    }

    function testASecondNotOkAfterAResendReopensTheCorrection()
    {
        $this->moderate([$this->document => 'invalid', $this->name => 'valid']);

        $registration = $this->registration();
        $this->login($registration->owner->user);
        $registration->sendEditableFields();
        $this->assertNotNull($this->registration()->editSentTimestamp, 'A mestra reenviou');
        $this->assertSame(RegistrationEvaluation::STATUS_DRAFT, (int) $this->app->conn->fetchOne('SELECT status FROM registration_evaluation WHERE id = ?', [$this->evaluationId]), 'O parecer volta à moderadora');

        $this->moderate([$this->document => 'invalid', $this->name => 'valid']);
        $registration = $this->registration();
        $this->assertNull($registration->editSentTimestamp, 'A correção reabre');
        $this->assertTrue(Moderation::isPending($registration), 'E a mestra pode reenviar de novo');
    }

    // O selo sai antes do e-mail, que leva o link do certificado.
    function testAllOkApprovesWithTheCertificateLink()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;

        $this->moderate([$this->document => 'valid', $this->name => 'valid']);
        $registration = $this->registration();

        $this->assertSame(Registration::STATUS_APPROVED, $registration->status);
        $relation = $this->app->repo('AgentSealRelation')->findOneBy(['seal' => $seal, 'owner' => $registration->owner]);
        $this->assertNotNull($relation, 'A mestra ganha o selo');

        $approved = $this->jobs(SendMailNotification::SLUG, '%' . Moderation::MAIL_APPROVED . '%');
        $this->assertCount(1, $approved);
        $this->assertSame($relation->singleUrl, json_decode($approved[0], true)['params']['certificateLink'] ?? null, 'O e-mail leva o certificado');
    }

    // A aprovação chega à fase final (Approval::allowFinalPhaseSync), e a inscrição copiada não manda outro e-mail.
    function testTheApprovalReachesTheFinalPhaseWithASingleMail()
    {
        // Conta os e-mails de aprovação ao rodar o job, pela inscrição de cada um.
        $mails = [];
        $this->app->hook('job(' . SendMailNotification::SLUG . ').execute:before', function () use (&$mails) {
            if ($this->template === Moderation::MAIL_APPROVED) {
                $mails[$this->registrationId] = true;
            }
        });

        $this->moderate([$this->document => 'valid', $this->name => 'valid']);
        $this->runJobs();
        $registration = $this->registration();

        $copies = (int) $this->app->conn->fetchOne('SELECT count(*) FROM registration WHERE number = :number AND opportunity_id = :last', ['number' => $registration->number, 'last' => $registration->opportunity->lastPhase->id]);
        $this->assertSame(1, $copies, 'A inscrição chega à fase final');
        $this->assertSame([$registration->id], array_keys($mails), 'Um e-mail de aprovação só, o da fase de cadastro');
    }

    // Sem o selo do programa configurado, a aprovação segue e o aviso sai, sem o certificado.
    function testWithoutTheSealTheApprovalMailHasNoCertificate()
    {
        unset($this->app->config[ProgramSeal::CONFIG_KEY]);

        $this->moderate([$this->document => 'valid', $this->name => 'valid']);

        $this->assertSame(Registration::STATUS_APPROVED, $this->registration()->status);
        $approved = $this->jobs(SendMailNotification::SLUG, '%' . Moderation::MAIL_APPROVED . '%');
        $this->assertCount(1, $approved, 'O aviso sai');
        $this->assertNull(json_decode($approved[0], true)['params']['certificateLink'] ?? null, 'Sem o link do certificado');
    }

    /** @return ?array o `:moderation` do aviso na ficha, como a página o entrega a quem a abre */
    private function noticeFor(User $user): ?array
    {
        $this->login($this->app->repo('User')->find($user->id));
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('registration', 'single', [$this->registrationId]), false);

        // Segue o redirecionamento da ficha para o endereço dela (/inscricao/ID/).
        if ($location = $this->app->response->getHeaderLine('Location')) {
            $this->app->reset();
            $this->app->run(new ServerRequest(method: 'GET', uri: parse_url($location, PHP_URL_PATH)), false);
        }

        $body = (string) $this->app->response->getBody();

        if (!preg_match('/<mdc-moderation-notice :moderation="([^"]*)"/', $body, $match)) {
            return null;
        }

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    // Só a dona vê, na ficha, o resultado de cada campo com o motivo e o caminho para corrigir, sem o nome de quem
    // avaliou.
    function testTheNoticeGoesOnlyToTheOwnerWithoutTheModeratorName()
    {
        $this->moderate([$this->document => 'invalid', $this->name => 'valid']);
        $owner = $this->registration()->owner->user;

        $notice = $this->noticeFor($owner);
        $this->assertSame('pending', $notice['status'] ?? null);
        $this->assertSame([[$this->document, 'invalid', 'Não comprova.'], [$this->name, 'valid', '']], array_map(fn ($field) => [$field['name'], $field['result'], $field['obs']], $notice['fields']));
        $this->assertStringContainsString("/{$this->registrationId}/", $notice['editUrl']);
        $this->assertStringNotContainsString($this->moderator->profile->name, json_encode($notice, JSON_UNESCAPED_UNICODE), 'Sem o nome de quem avaliou');

        $this->assertNull($this->noticeFor($this->moderator), 'A moderadora não vê o aviso da dona');
    }
}
