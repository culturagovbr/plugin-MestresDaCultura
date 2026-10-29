<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivitySeal;
use MDC\Services\FestivitySearch;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A busca de festejos traz só os publicados, com o selo dos festejos, e filtra pelos campos do festejo e pelo mês
// da agenda; o contador da home conta os publicados.
class FestivitySearchTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    private function published(string $name, array $typologies, string $startsOn): Event
    {
        $event = $this->draft();
        $this->complete($event, withPeriod: false);
        $event->name = $name;
        $event->{FestivityFields::SEGMENT} = 'Celebrações e Rituais';
        $event->{FestivityFields::TYPOLOGIES} = $typologies;
        $event->save(true);
        $this->period($event, $this->place($event), $startsOn);
        $this->app->em->refresh($event);
        $event->publish(true);

        return $event;
    }

    /** @return list<int> */
    private function find(array $params): array
    {
        $query = new ApiQuery(Event::class, ['@select' => 'id', '@seals' => (string) $this->festivitySeal()->id] + $params);

        return array_column($query->find(), 'id');
    }

    function testTheSearchFiltersByTypologyAndMonth()
    {
        $samba = $this->published('Samba de Roda de Santa Bárbara', ['Festas de Santos/Devocional'], '2026-12-03');
        $quadrilha = $this->published('Quadrilha de São João', ['Festas do Ciclo Junino'], '2026-06-24');
        $this->draft();
        $this->logout();

        $this->assertEqualsCanonicalizing([$samba->id, $quadrilha->id], $this->find([]), 'Só os publicados');
        $this->assertSame([$samba->id], $this->find([FestivityFields::TYPOLOGIES => 'ILIKE(*"Festas de Santos/Devocional"*)']), 'Pela tipologia, com a barra e o acento');
        $this->assertSame([$quadrilha->id], $this->find([FestivitySearch::MONTH_PARAM => '6']), 'Pelo mês da agenda');
        $this->assertSame([], $this->find([FestivitySearch::MONTH_PARAM => '2']), 'Mês sem festejo, nenhum');
    }

    // A lista do mês só traz festejos com o selo.
    function testTheMonthListHasOnlyFestivities()
    {
        $samba = $this->published('Samba de Roda de Santa Bárbara', ['Festas de Santos/Devocional'], '2026-12-03');
        $event = $this->draft();
        $this->period($event, $this->place($event), '2026-12-05');
        $this->app->em->refresh($event);
        $event->publish(true);
        $this->assertSame(Event::STATUS_ENABLED, $event->status, 'Publicado, mas sem as 6 etapas: sem o selo');

        $this->assertSame([$samba->id], FestivitySearch::idsInMonth(12));
    }

    function testTheHomeCounterCountsThePublishedFestivities()
    {
        $this->published('Samba de Roda de Santa Bárbara', ['Festas de Santos/Devocional'], '2026-12-03');
        $this->draft();

        $this->assertSame(1, (new FestivitySeal)->countPublished());
    }

    function testTheSearchPageOpensForVisitors()
    {
        $this->logout();
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'festivities'), false);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $this->assertStringContainsString('<mdc-festivities-search', (string) $this->app->response->getBody());
    }
}
