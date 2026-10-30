<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivitySeal;
use MDC\Services\FestivitySearch;
use MDC\Services\ProgramSeal;
use Tests\Builders\SealBuilder;
use Tests\Abstract\TestCase;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A busca de festejos traz só os publicados, com o selo dos festejos, e filtra pelos campos do festejo e pelo mês
// da agenda; o contador da home conta os publicados.
class FestivitySearchTest extends TestCase
{
    use BuildsFestivities;
    use CapturesLog;
    use OpportunityBuilder;
    use ProgramOpportunity;
    use ProgramSubsite;
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
        $this->assertSame(Event::STATUS_DRAFT, (int) $this->app->conn->fetchOne('SELECT status FROM event WHERE id = ?', [$event->id]), 'Sem as 6 etapas, nem publica');

        $this->assertSame([$samba->id], FestivitySearch::idsInMonth(12));
    }

    // O festejo de janeiro a março também está em fevereiro, e não só nos meses do começo e do fim.
    function testTheMonthCoversTheWholePeriod()
    {
        $event = $this->draft();
        $this->complete($event, withPeriod: false);
        $occurrence = $this->period($event, $this->place($event), '2027-01-10');
        $occurrence->rule = ['startsOn' => '2027-01-10', 'until' => '2027-03-20', 'startsAt' => '18:00', 'duration' => 300, 'frequency' => 'daily'];
        $occurrence->save(true);
        $this->app->em->refresh($event);
        $event->publish(true);

        $this->assertContains($event->id, FestivitySearch::idsInMonth(2));
        $this->assertNotContains($event->id, FestivitySearch::idsInMonth(4));
    }

    // "Com Mestras e Mestres da Rede" conta só as marcadas com o selo do programa.
    function testWithMastersMeansMastersOfTheNetwork()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
        $master = $this->userDirector->createUser()->profile;
        $this->app->disableAccessControl();
        $master->createSealRelation($seal, true, true);
        $this->app->enableAccessControl();
        $outsider = $this->userDirector->createUser()->profile;

        $with = $this->published('Samba de Roda de Santa Bárbara', ['Festas de Santos/Devocional'], '2026-12-03');
        $without = $this->published('Quadrilha do Arraiá', ['Festas de Santos/Devocional'], '2026-06-20');
        $this->app->disableAccessControl();
        foreach ([[$with, $master], [$without, $outsider]] as [$event, $agent]) {
            $event = $this->app->repo('Event')->find($event->id);
            $event->{FestivityFields::WITH_MASTERS} = 'Sim';
            $event->{FestivityFields::MASTERS} = "|{$agent->id}|";
            $event->save(true);
        }
        $this->app->enableAccessControl();

        $this->assertSame([$with->id], $this->find([FestivitySearch::MASTERS_PARAM => '1']));
    }

    // O contador da home conta só os festejos publicados do subsite dos Mestres.
    function testTheHomeCounterCountsThePublishedFestivities()
    {
        $this->published('Samba de Roda de Santa Bárbara', ['Festas de Santos/Devocional'], '2026-12-03');
        $this->published('Quadrilha do Arraiá', ['Festas de Santos/Devocional'], '2026-06-20');
        $elsewhere = $this->published('Folia de Reis', ['Festas de Santos/Devocional'], '2026-12-24');
        $this->draft();

        $other = $this->createProgramSubsite($elsewhere->owner->id);
        $this->putInSubsite('event', $elsewhere->id, $other);
        $this->assertSame(2, (new FestivitySeal)->countPublished(), 'Sem subsite no programa');

        $this->putInSubsite('opportunity', $this->createProgramOpportunity()->id, $other);
        $this->assertSame(1, (new FestivitySeal)->countPublished(), 'Com o programa num subsite');
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
