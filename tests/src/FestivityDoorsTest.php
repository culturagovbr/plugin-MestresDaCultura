<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Space;
use MDC\Services\FestivityFields;
use Tests\Abstract\TestCase;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * Os caminhos que gravam o festejo, o local e o período. As regras valem pelo subsite do programa gravado no dado,
 * também pelo Mapa principal (outsideTheSubsite), pela API e para outra conta.
 */
class FestivityDoorsTest extends TestCase
{
    use BuildsFestivities;
    use CapturesLog;
    use OpportunityBuilder;
    use OutsideTheSubsite;
    use ProgramOpportunity;
    use RequestFactory;
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureLog();
        $this->createProgramOpportunity();
    }

    private function statusOf(string $table, int $id): int
    {
        return (int) $this->app->conn->fetchOne("SELECT status FROM {$table} WHERE id = ?", [$id]);
    }

    private function occurrencesOf(Event $event): array
    {
        return $this->app->repo('EventOccurrence')->findBy(['event' => $this->app->repo('Event')->find($event->id)]);
    }

    // Pelo Mapa principal, o festejo sem as 6 etapas não passa a publicado.
    function testOutsideTheSubsiteAnIncompleteFestivityStaysDraft()
    {
        $event = $this->draft();

        $this->outsideTheSubsite(function () use ($event) {
            $found = $this->app->repo('Event')->find($event->id);
            $found->status = Event::STATUS_ENABLED;
            $found->save(true);
            $this->app->repo('Event')->find($event->id)->publish(true);
        });

        $this->assertSame(Event::STATUS_DRAFT, $this->statusOf('event', $event->id));
        $this->assertSame(0, (int) $this->app->conn->fetchOne('SELECT count(*) FROM seal_relation WHERE object_id = ?', [$event->id]));
    }

    // O período do festejo só vai para um local do festejo; o espaço de outra pessoa ou de outro subsite é recusado na
    // validação.
    function testAPeriodGoesOnlyToAPlaceOfTheFestivity()
    {
        $theirs = $this->place($this->draft());
        $elsewhere = $this->draft();
        $otherSubsite = $this->place($elsewhere);
        $this->app->conn->executeStatement('UPDATE space SET subsite_id = (SELECT min(id) FROM subsite) WHERE id = ?', [$otherSubsite->id]);
        $event = $this->draft($elsewhere->owner->user);
        $own = $this->place($event);

        $errors = fn (int $space) => $this->outsideTheSubsite(function () use ($event, $space) {
            $this->app->em->clear();
            $occurrence = new EventOccurrence;
            $occurrence->event = $this->app->repo('Event')->find($event->id);
            $occurrence->space = $this->app->repo('Space')->find($space);
            $occurrence->rule = ['startsOn' => '2026-12-03', 'startsAt' => '18:00', 'duration' => 300, 'frequency' => 'once'];

            return $occurrence->getValidationErrors();
        });

        $this->assertArrayHasKey('space', $errors($theirs->id), 'O espaço de outra pessoa');
        if ($this->app->conn->fetchOne('SELECT subsite_id FROM space WHERE id = ?', [$otherSubsite->id])) {
            $this->assertArrayHasKey('space', $errors($otherSubsite->id), 'O espaço de outro subsite');
        }
        $this->assertArrayNotHasKey('space', $errors($own->id), 'O local de quem cadastrou o festejo');
    }

    // O festejo nunca publica nem despublica o espaço de outra pessoa onde tenha um período, pendente ou aceito.
    function testTheFestivityNeverMovesSomeoneElsesSpace()
    {
        $theirs = $this->place($this->draft());
        $this->app->conn->executeStatement('UPDATE space SET status = 1 WHERE id = ?', [$theirs->id]);
        $event = $this->draft();
        $this->complete($event);

        foreach ([EventOccurrence::STATUS_PENDING, EventOccurrence::STATUS_ENABLED] as $status) {
            $this->app->disableAccessControl();
            $occurrence = $this->period($this->app->repo('Event')->find($event->id), $this->app->repo('Space')->find($theirs->id));
            $this->app->conn->executeStatement('UPDATE event_occurrence SET status = ? WHERE id = ?', [$status, $occurrence->id]);
            $this->app->enableAccessControl();
        }

        $this->outsideTheSubsite(function () use ($event) {
            $this->app->repo('Event')->find($event->id)->publish(true);
            $this->app->repo('Event')->find($event->id)->unpublish(true);
        });

        $this->assertSame(Space::STATUS_ENABLED, $this->statusOf('space', $theirs->id), 'O espaço alheio fica como estava');
    }

    // Só quem altera o festejo apaga o período, e o festejo publicado não fica sem agenda.
    function testOnlyWhoEditsTheFestivityRemovesAPeriodAndThePublishedKeepsOne()
    {
        $event = $this->draft();
        $owner = $event->owner->user;
        $this->complete($event);
        $first = $this->occurrencesOf($event)[0]->id;
        $canRemove = fn () => $this->app->repo('EventOccurrence')->find($first)->canUser('remove');

        $this->login($this->userDirector->createUser());
        $this->assertFalse($canRemove(), 'Outra conta não apaga');

        $this->login($owner);
        $this->assertTrue($canRemove(), 'A dona apaga do rascunho');
        $this->app->repo('Event')->find($event->id)->publish(true);
        $this->assertFalse($canRemove(), 'Publicado, o único período fica');

        $published = $this->app->repo('Event')->find($event->id);
        $this->period($published, $this->place($published));
        $this->assertTrue($canRemove(), 'Com outro na agenda, sai');
    }

    // O PATCH com `spaceId` não muda o local do período.
    function testThePatchDoesNotMoveAPeriod()
    {
        $theirs = $this->place($this->draft());
        $event = $this->draft();
        $this->complete($event);
        $occurrence = $this->occurrencesOf($event)[0];
        $space = $occurrence->space->id;

        $this->app->reset();
        $this->app->run($this->requestFactory->PATCH('eventoccurrence', 'single', [$occurrence->id], ['spaceId' => $theirs->id, 'SpaceId' => $theirs->id]), false);

        $this->assertSame($space, (int) $this->app->conn->fetchOne('SELECT space_id FROM event_occurrence WHERE id = ?', [$occurrence->id]));
    }

    // O local é publicado só pela sincronização com o festejo; o endereço mudado pelo Mapa principal chega ao festejo.
    function testThePlaceFollowsTheFestivityOutsideTheSubsiteToo()
    {
        $event = $this->draft();
        $this->complete($event);
        $place = $this->occurrencesOf($event)[0]->space->id;

        $this->outsideTheSubsite(function () use ($place) {
            $found = $this->app->repo('Space')->find($place);
            $found->status = Space::STATUS_ENABLED;
            $found->En_Municipio = 'Salvador';
            $found->save(true);
        });

        $this->assertSame(Space::STATUS_DRAFT, $this->statusOf('space', $place), 'Sem festejo publicado, não publica');
        $this->assertSame('Salvador', $this->app->conn->fetchOne('SELECT value FROM event_meta WHERE object_id = ? AND key = ?', [$event->id, FestivityFields::CITY]));
    }

    // No festejo publicado, o PATCH cobra também as etapas que faltam fora dos campos enviados.
    function testAPublishedFestivityKeepsTheSixSteps()
    {
        $event = $this->draft();
        $this->complete($event);
        $this->app->repo('Event')->find($event->id)->publish(true);

        $this->app->reset();
        $this->app->run($this->requestFactory->PATCH('event', 'single', [$event->id], [FestivityFields::HAS_ACCESSIBILITY => 'Sim']), false);

        $this->assertSame(400, $this->app->response->getStatusCode(), 'Sem a lista de recursos, não grava');
        $this->assertFalse($this->app->conn->fetchOne('SELECT value FROM event_meta WHERE object_id = ? AND key = ?', [$event->id, FestivityFields::HAS_ACCESSIBILITY]));
    }
}
