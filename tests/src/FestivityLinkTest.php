<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Seal;
use MDC\Services\FestivityField;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityProfile;
use MDC\Services\FestivitySeal;
use MDC\Services\FixedOpportunity;
use MDC\Services\MasterProfile;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// A pergunta "Identifique o festejo no calendário" escolhe um festejo publicado, e o vínculo aparece no perfil da
// mestra e na página do festejo.
class FestivityLinkTest extends TestCase
{
    use BuildsFestivities;
    use CapturesLog;
    use OpportunityBuilder;
    use RegistrationDirector;
    use UserDirector;

    private Opportunity $opportunity;
    private Seal $seal;
    private \MapasCulturais\Entities\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureLog();

        $admin = $this->admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        $this->opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('festejo', FestivityField::SLUG, title: FestivityField::TITLE)
                ->done()
            ->save()
            ->refresh()
            ->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunity->id;

        $this->seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $this->seal->id;
    }

    private function published(string $name): Event
    {
        $event = $this->draft();
        $this->complete($event);
        $event->name = $name;
        $event->save(true);
        $event->publish(true);

        return $event;
    }

    // Mestra aprovada, com o selo, que respondeu a pergunta do festejo.
    private function master(string $answer): Agent
    {
        $this->login($this->admin);
        $registration = $this->registrationDirector->createDraftRegistrations($this->opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $this->app->disableAccessControl();
        $registration->{$this->opportunityBuilder->getFieldName('festejo')} = $answer;
        $registration->save(true);
        $registration->owner->createSealRelation($this->seal, true, true);
        $this->app->enableAccessControl();
        $this->app->conn->executeStatement('UPDATE registration SET status = 10 WHERE id = :id', ['id' => $registration->id]);

        return $registration->owner;
    }

    function testTheFieldTakesOnlyPublishedFestivities()
    {
        $published = $this->published('Samba de Roda de Santa Bárbara');
        $draft = $this->draft();

        $this->assertTrue(FestivityField::isValid((string) $published->id));
        $this->assertFalse(FestivityField::isValid((string) $draft->id), 'O rascunho não');
        $this->assertTrue(FestivityField::isValid('samba de roda de santa barbara'), 'A resposta em texto, de antes da busca, segue');

        // A ficha e a planilha só mostram o nome de festejo do Calendário.
        $this->assertSame('Samba de Roda de Santa Bárbara', FestivityField::toText((string) $published->id));
        $this->assertStringNotContainsString((string) $draft->name, FestivityField::toText((string) $draft->id));
    }

    function testTheMastersQuestionBecomesTheFestivityField()
    {
        $field = $this->opportunity->registrationFieldConfigurations[0];
        $this->app->conn->executeStatement('UPDATE registration_field_configuration SET field_type = :type WHERE id = :id', ['type' => 'text', 'id' => $field->id]);

        FestivityField::convertTheMastersQuestion();

        $converted = $this->app->conn->fetchAssociative('SELECT field_type, description FROM registration_field_configuration WHERE id = :id', ['id' => $field->id]);
        $this->assertSame(FestivityField::SLUG, $converted['field_type']);
        $this->assertSame(FestivityField::description(), $converted['description'], 'A ajuda passa a ser a da busca');
    }

    function testTheLinkShowsOnBothSides()
    {
        $chosen = $this->published('Samba de Roda de Santa Bárbara');
        $master = $this->master((string) $chosen->id);
        $marking = $this->published('Roda de Coco de São João');
        $marking->{FestivityFields::WITH_MASTERS} = 'Sim';
        $marking->{FestivityFields::MASTERS} = "|{$master->id}|";
        $marking->save(true);

        // Publicado sem o selo, não é festejo do Calendário.
        $unsealed = $this->draft();
        $unsealed->{FestivityFields::WITH_MASTERS} = 'Sim';
        $unsealed->{FestivityFields::MASTERS} = "|{$master->id}|";
        $unsealed->save(true);
        $unsealed->publish(true);
        $this->logout();

        $this->app->em->clear();
        $profile = (new MasterProfile(new FixedOpportunity, new ProgramSeal))->of($this->app->repo('Agent')->find($master->id));
        $this->assertEqualsCanonicalizing([$chosen->id, $marking->id], array_column($profile['festivities'], 'id'), 'O que ela escolheu e o que a marcou, só os com o selo');

        $festivity = (new FestivityProfile(new FestivitySeal, new ProgramSeal))->of($this->app->repo('Event')->find($chosen->id));
        $this->assertSame([$master->id], array_column($festivity['masters'], 'id'), 'A mestra que escolheu o festejo aparece nele');
    }
}
