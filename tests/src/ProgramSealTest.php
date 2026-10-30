<?php

namespace Tests\MDC;

use MDC\Services\FestivitySeal;
use MDC\Services\FixedOpportunity;
use MDC\Services\MasterPublicData;
use MDC\Services\Moderation;
use MDC\Services\ProgramSeal;
use MailNotification\JobTypes\SendMailNotification;
use MapasCulturais\Entities\Registration;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * Os serviços do plugin releem a entidade configurada depois de o Doctrine ser limpo, como no "Aplicar resultado" em
 * lote da avaliação.
 */
class ProgramSealTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use ProgramOpportunity;
    use RequestFactory;
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

    // Pela rota do core, as duas inscrições do lote ganham o selo, os dados públicos e o e-mail com o certificado.
    function testApplyingTheResultApprovesTheWholeBatch()
    {
        $this->captureLog();
        $opportunity = $this->createProgramOpportunity();
        $seal = (new SealBuilder)->reset($this->app->user->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
        $this->opportunityBuilder->createSentRegistrations(number_of_registrations: 2);

        $ids = $this->app->conn->fetchFirstColumn('SELECT id FROM registration WHERE opportunity_id = ? ORDER BY id', [$opportunity->id]);
        $this->app->disableAccessControl();
        foreach ($ids as $id) {
            $owner = $this->app->repo('Registration')->find($id)->owner;
            $owner->nomeSocial = "Mestra {$id}";
            $owner->save(true);
        }
        $this->app->enableAccessControl();
        $this->app->conn->executeStatement("UPDATE registration SET consolidated_result = '1' WHERE id IN (?, ?)", $ids);

        $this->app->reset();
        $this->app->run($this->requestFactory->POST('opportunity', 'applyEvaluationsDocumentary', [$opportunity->id], ['from' => '1', 'to' => Registration::STATUS_APPROVED]), false);
        $this->assertSame(200, $this->app->response->getStatusCode(), (string) $this->app->response->getBody());

        $this->assertSame([Registration::STATUS_APPROVED, Registration::STATUS_APPROVED], array_map('intval', $this->app->conn->fetchFirstColumn('SELECT status FROM registration WHERE id IN (?, ?) ORDER BY id', $ids)));
        $this->assertSame(2, (int) $this->app->conn->fetchOne('SELECT count(*) FROM seal_relation WHERE seal_id = ?', [$seal->id]), 'As duas ganham o selo');
        foreach ($ids as $id) {
            $agent = $this->app->conn->fetchOne('SELECT agent_id FROM registration WHERE id = ?', [$id]);
            $this->assertSame("Mestra {$id}", $this->app->conn->fetchOne('SELECT value FROM agent_meta WHERE object_id = ? AND key = ?', [$agent, MasterPublicData::KNOWN_AS]), 'E os dados públicos');
            $this->assertSame(1, (int) $this->app->conn->fetchOne("SELECT count(*) FROM job WHERE name = ? AND metadata->>'template' = ? AND metadata->>'registrationId' = ?", [SendMailNotification::SLUG, Moderation::MAIL_APPROVED, (string) $id]), 'E o e-mail');
        }
    }

    // O cadastro aprovado excluído leva o selo e os dados públicos; o não aprovado excluído não mexe no selo do agente.
    function testDeletingAnApprovedRegistrationTakesTheSealAndThePublicData()
    {
        $this->captureLog();
        $opportunity = $this->createProgramOpportunity();
        $seal = (new SealBuilder)->reset($this->app->user->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
        $this->opportunityBuilder->createSentRegistrations(number_of_registrations: 2);

        [$approved, $sent] = $this->app->conn->fetchFirstColumn('SELECT id FROM registration WHERE opportunity_id = ? ORDER BY id', [$opportunity->id]);
        $agentOf = fn (int $id): int => (int) $this->app->conn->fetchOne('SELECT agent_id FROM registration WHERE id = ?', [$id]);
        $sealsOf = fn (int $agent): int => (int) $this->app->conn->fetchOne('SELECT count(*) FROM seal_relation WHERE seal_id = ? AND object_id = ?', [$seal->id, $agent]);
        $approvedAgent = $agentOf($approved);
        $sentAgent = $agentOf($sent);

        $this->app->disableAccessControl();
        $registration = $this->app->repo('Registration')->find($approved);
        $registration->owner->nomeSocial = 'Mestra Celina';
        $registration->owner->save(true);
        $registration->setStatusToApproved(true);
        (new ProgramSeal)->apply($this->app->repo('Registration')->find($sent));
        $this->app->enableAccessControl();

        $this->assertSame(1, $sealsOf($approvedAgent));
        $this->assertSame('Mestra Celina', $this->app->conn->fetchOne('SELECT value FROM agent_meta WHERE object_id = ? AND key = ?', [$approvedAgent, MasterPublicData::KNOWN_AS]));

        foreach ([$approved, $sent] as $id) {
            $this->app->reset();
            $this->app->run($this->requestFactory->DELETE('registration', 'single', [$id]), false);
            $this->assertSame(200, $this->app->response->getStatusCode(), (string) $this->app->response->getBody());
            $this->assertFalse($this->app->conn->fetchOne('SELECT id FROM registration WHERE id = ?', [$id]), 'A inscrição sai');
        }

        $this->assertSame(0, $sealsOf($approvedAgent), 'O aprovado perde o selo');
        $this->assertEmpty($this->app->conn->fetchOne('SELECT value FROM agent_meta WHERE object_id = ? AND key = ?', [$approvedAgent, MasterPublicData::KNOWN_AS]), 'E os dados públicos');
        $this->assertSame(1, $sealsOf($sentAgent), 'O selo de quem não estava aprovado fica');
    }
}
