<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Space;
use MDC\Services\FestivityField;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// No subsite dos Mestres, os espaços e as ocorrências vêm só do subsite. Nos testes, o subsite atual é o site
// principal e o "outro" é o do ProgramSubsite.
class SubsiteIsolationTest extends TestCase
{
    use BuildsFestivities;
    use ProgramSubsite;
    use RequestFactory;
    use UserDirector;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = $this->createProgramSubsite($this->userDirector->createUser('admin')->profile->id);
    }

    function testOnlyThePlacesOfTheSubsiteAreListed()
    {
        $event = $this->draft();
        $here = $this->place($event, 'Cachoeira', 'BA');
        $there = $this->place($event, 'Recife', 'PE');
        $this->putInSubsite('space', $there->id, $this->other);

        // Consulta como a etapa 2: os locais em rascunho de quem os controla, com o cache de permissão refeito.
        $this->processPCache();
        $found = (new ApiQuery(Space::class, ['@select' => 'id', '@permissions' => '@control', 'status' => 'GTE(0)', 'owner' => "EQ({$event->owner->id})"]))->find();

        $this->assertSame([$here->id], array_column($found, 'id'));
    }

    function testOccurrencesOfDraftsAndOfOtherSubsitesStayOut()
    {
        $published = $this->draft();
        $this->complete($published, false);
        $seen = $this->period($published, $this->place($published));
        $published->publish(true);
        $draft = $this->draft();
        $hidden = $this->period($draft, $this->place($draft));
        $owner = $draft->owner->user;
        $elsewhere = $this->draft();
        $this->complete($elsewhere, false);
        $this->period($elsewhere, $this->place($elsewhere));
        $elsewhere->publish(true);
        $this->putInSubsite('event', $elsewhere->id, $this->other);

        $this->logout();
        $this->assertSame([$seen->id], array_column((new ApiQuery(EventOccurrence::class, ['@select' => 'id']))->find(), 'id'), 'O visitante vê só o festejo publicado do subsite');

        $this->login($owner);
        $agenda = (new ApiQuery(EventOccurrence::class, ['@select' => 'id', 'event' => "EQ({$draft->id})"]))->find();
        $this->assertSame([$hidden->id], array_column($agenda, 'id'), 'A agenda do rascunho segue para quem o edita');

        // Outra conta, pelo mesmo filtro, não lê as datas e o local do rascunho alheio.
        $this->login($this->userDirector->createUser());
        $this->assertSame([], (new ApiQuery(EventOccurrence::class, ['@select' => 'id', 'event' => "EQ({$draft->id})"]))->find());
    }

    function testAnEventOfAnotherSubsiteGoesHome()
    {
        $event = $this->draft();
        $event->publish(true);
        $this->putInSubsite('event', $event->id, $this->other);
        $this->logout();

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('event', 'single', [$event->id]), false);

        $this->assertContains($this->app->response->getStatusCode(), [301, 302, 303, 307]);
        $this->assertStringContainsString('mestres.test', $this->app->response->getHeaderLine('Location'));
    }

    function testTheCoreEventSearchLeadsToTheFestivities()
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('search', 'events'), false);

        $this->assertSame($this->app->createUrl('mdc', 'festivities'), $this->app->response->getHeaderLine('Location'));
    }

    function testAPeriodTakesOnlyAPlaceOfTheSubsite()
    {
        $event = $this->draft();
        $there = $this->place($event, 'Recife', 'PE');
        $this->putInSubsite('space', $there->id, $this->other);

        $occurrence = new EventOccurrence;
        $occurrence->event = $this->app->repo('Event')->find($event->id);
        $occurrence->space = $this->app->repo('Space')->find($there->id);
        $occurrence->rule = ['startsOn' => '2026-12-03', 'startsAt' => '18:00', 'frequency' => 'once', 'description' => '3 de dezembro'];

        $this->assertArrayHasKey('space', $occurrence->getValidationErrors());
    }

    function testTheMastersFormTakesOnlyFestivities()
    {
        $event = $this->draft();
        $this->putInSubsite('event', $event->id, $this->other);
        $this->login($this->userDirector->createUser('admin'));
        $other = $this->app->repo('Event')->find($event->id);
        $other->publish(true);

        $this->assertFalse(FestivityField::isValid((string) $event->id), 'Evento publicado de outro subsite não é festejo');
    }
}
