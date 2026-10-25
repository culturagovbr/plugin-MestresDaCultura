<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use MDC\Services\PanelSummary;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Painel inicial conforme o papel: o moderador vê o que está com ele; quem controla o programa, os atalhos de gestão e
// os cadastros por situação. Contagens agregadas.
class PanelSummaryTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private User $manager;

    private Opportunity $fixed;

    private User $moderator;

    private function createProgram(): void
    {
        $this->manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($this->manager);

        $phase = $this->opportunityBuilder
            ->reset(owner: $this->manager->profile, owner_entity: $this->manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->createSentRegistrations(number_of_registrations: 2)
            ->addEvaluationPhase(EvaluationMethods::documentary)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration('Moderação', 2)
                ->save();
        $relation = $phase->addValuer('Moderação', 'Moderadora')->getInstance();
        $phase->redistributeCommitteeRegistrations();

        $this->fixed = $this->opportunityBuilder->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->fixed->id;
        $this->moderator = $this->app->repo('User')->find($relation->agent->user->id);
    }

    private function summaryFor(User $user): array
    {
        $this->app->em->clear();
        $this->login($this->app->repo('User')->find($user->id));

        return (new PanelSummary(new FixedOpportunity))->for($this->app->user);
    }

    function testTheModeratorSeesWhatIsWithHer()
    {
        $this->createProgram();
        $registration = $this->app->repo('Registration')->findOneBy(['opportunity' => $this->fixed]);

        // Uma das duas iniciada (rascunho do parecer); a outra, pendente.
        $this->app->disableAccessControl();
        $evaluation = new RegistrationEvaluation;
        $evaluation->registration = $registration;
        $evaluation->user = $this->moderator;
        $evaluation->committee = 'Moderação';
        $evaluation->status = RegistrationEvaluation::STATUS_DRAFT;
        $evaluation->save(true);
        $this->app->enableAccessControl();

        $moderation = $this->summaryFor($this->moderator)['moderation'];

        $this->assertSame(['pending' => 1, 'started' => 1, 'completed' => 0, 'sent' => 0], array_intersect_key($moderation['counts'], array_flip(['pending', 'started', 'completed', 'sent'])));
        $phase = (new FixedOpportunity)->moderatedBy($this->moderator);
        $this->assertSame($this->app->createUrl('opportunity', 'userEvaluations', [$phase->id]), $moderation['url'], 'O link é o da lista dela, o mesmo do menu');
        $this->assertNull($this->summaryFor($this->moderator)['management'], 'A moderadora não controla o programa');
    }

    function testWhoControlsTheProgramSeesTheManagement()
    {
        $this->createProgram();

        $management = $this->summaryFor($this->userDirector->createUser('admin'))['management'];

        $this->assertSame(['Cadastros', 'Avaliações', 'Comissão'], array_column($management['shortcuts'], 'label'));
        $this->assertSame(2, $management['counts']['waiting'], 'Os dois enviados aguardam moderação');
    }

    function testTheMasterSeesNoRoleBlock()
    {
        $this->createProgram();

        $this->assertSame(['moderation' => null, 'management' => null], $this->summaryFor($this->userDirector->createUser()));
    }

    // A contagem segue a regra da lista de cadastros (Moderation::situation).
    function testTheCountsFollowTheModerationSituation()
    {
        $this->createProgram();
        $ids = $this->app->conn->fetchFirstColumn('SELECT id FROM registration WHERE opportunity_id = :id ORDER BY id', ['id' => $this->fixed->id]);
        $this->app->conn->executeStatement("UPDATE registration SET editable_fields = '[\"rfc_41\"]' WHERE id = :id", ['id' => $ids[0]]);
        $this->app->conn->executeStatement("UPDATE registration SET editable_fields = '[\"rfc_41\"]', edit_sent_timestamp = now() WHERE id = :id", ['id' => $ids[1]]);
        // Um segundo devolvido, para as contagens de devolvido e reenviado diferirem.
        foreach ([[Registration::STATUS_DRAFT, null, null], [Registration::STATUS_APPROVED, null, null], [Registration::STATUS_SENT, '[]', null], [Registration::STATUS_SENT, '["rfc_41"]', null]] as [$status, $fields, $resent]) {
            $this->app->conn->executeStatement("INSERT INTO registration (id, opportunity_id, agent_id, status, create_timestamp, number, editable_fields, edit_sent_timestamp) SELECT nextval('registration_id_seq'), opportunity_id, agent_id, :status, now(), 'x' || nextval('registration_id_seq'), CAST(:fields AS json), CAST(:resent AS timestamp) FROM registration WHERE id = :id", ['status' => $status, 'fields' => $fields, 'resent' => $resent, 'id' => $ids[0]]);
        }

        $expected = array_fill_keys(Moderation::SITUATIONS, 0);
        foreach ($this->app->conn->fetchAllAssociative('SELECT status, editable_fields, edit_sent_timestamp FROM registration WHERE opportunity_id = :id', ['id' => $this->fixed->id]) as $row) {
            $situation = Moderation::situation((int) $row['status'], json_decode((string) $row['editable_fields'], true), $row['edit_sent_timestamp']);
            if ($situation) {
                $expected[$situation]++;
            }
        }

        $this->assertSame(['draft' => 1, 'waiting' => 1, 'returned' => 2, 'resent' => 1, 'approved' => 1], $expected, 'Cadastros em todas as situações');
        $this->assertSame($expected, PanelSummary::countsBySituation($this->fixed->id));
    }
}
