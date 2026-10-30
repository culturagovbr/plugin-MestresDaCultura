<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Seal;
use MDC\Services\FestivityFields;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\SealBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Publicar dá ao festejo o selo dos festejos e despublicar o tira; publicar de novo mantém a data da primeira
// publicação. O selo só sai com as 6 etapas, e a data é sempre a do servidor.
class FestivitySealTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    // O selo vem do BuildsFestivities.
    private function relation(Event $event)
    {
        return $this->app->repo('EventSealRelation')->findOneBy(['seal' => $this->festivitySeal(), 'owner' => $event]);
    }

    private function storedFirstPublished(Event $event): ?string
    {
        return $this->app->conn->fetchOne('SELECT value FROM event_meta WHERE object_id = ? AND key = ?', [$event->id, FestivityFields::FIRST_PUBLISHED]) ?: null;
    }

    function testPublishingAppliesTheSealAndUnpublishingRemovesIt()
    {
        $event = $this->draft();
        $this->complete($event);
        $this->assertNull($this->relation($event), 'O rascunho não tem selo');

        $event->publish(true);
        $this->assertNotNull($this->relation($event), 'Publicado, tem o selo');

        $event->unpublish(true);
        $this->assertNull($this->relation($event), 'Despublicado, não tem mais');
    }

    function testPublishingAgainKeepsTheFirstDate()
    {
        $event = $this->draft();
        $this->complete($event);
        $event->publish(true);
        $event->unpublish(true);

        // Primeira publicação no ano passado.
        $this->app->conn->executeStatement('UPDATE event_meta SET value = ? WHERE object_id = ? AND key = ?', ['2025-12-01 10:00:00', $event->id, FestivityFields::FIRST_PUBLISHED]);
        $this->app->em->refresh($event);
        $event->publish(true);

        $this->assertSame('2025-12-01', $this->relation($event)->createTimestamp->format('Y-m-d'));
    }

    // Sem as 6 etapas, o publicar do core deixa o festejo em rascunho, sem o selo e sem a data.
    function testAFestivityWithoutTheSixStepsGetsNoSeal()
    {
        $event = $this->draft();
        $event->publish(true);
        $this->assertSame(Event::STATUS_DRAFT, (int) $this->app->conn->fetchOne('SELECT status FROM event WHERE id = ?', [$event->id]), 'Sem as 6 etapas, não publica');
        $this->assertNull($this->relation($event), 'Nem ganha o selo');
        $this->assertNull($this->storedFirstPublished($event), 'Nem a data: o certificado não conta de quando estava incompleto');

        $this->complete($event);
        $this->app->repo('Event')->find($event->id)->publish(true);
        $this->assertNotNull($this->relation($event), 'Completo, publica e ganha o selo');
        $this->assertSame(substr($this->storedFirstPublished($event), 0, 10), $this->relation($event)->createTimestamp->format('Y-m-d'), 'E conta desde ela');
    }

    function testTheFirstPublicationDateIsTheServers()
    {
        $event = $this->draft();
        $this->complete($event);
        $event->publish(true);
        $since = $this->storedFirstPublished($event);
        $this->assertNotNull($since);

        $this->app->reset();
        $this->app->run($this->requestFactory->PATCH_entity($event, [FestivityFields::FIRST_PUBLISHED => '2000-01-01 00:00:00', 'name' => 'Samba de Roda']), false);
        $this->assertSame($since, $this->storedFirstPublished($event), 'A data enviada é descartada');
        $this->assertSame('Samba de Roda', $this->app->conn->fetchOne('SELECT name FROM event WHERE id = ?', [$event->id]), 'O resto da edição segue');

        $draft = $this->draft();
        $this->app->reset();
        $this->app->run($this->requestFactory->PATCH_entity($draft, [FestivityFields::FIRST_PUBLISHED => '2000-01-01 00:00:00']), false);
        $this->assertNull($this->storedFirstPublished($draft), 'Nem o rascunho guarda uma data vinda de fora');

        // Fora da rota, a gravação também mantém a data gravada.
        $event = $this->app->repo('Event')->find($event->id);
        $this->login($event->owner->user);
        $event->{FestivityFields::FIRST_PUBLISHED} = '2000-01-01 00:00:00';
        $event->save(true);
        $this->assertSame($since, $this->storedFirstPublished($event), 'A gravação direta também é descartada');
    }

    // Com o filtro de selos ligado, quem não é admin vê o selo dos festejos e abre o certificado.
    function testWhoIsNotAdminSeesTheSealAndTheCertificate()
    {
        $event = $this->draft();
        $this->complete($event);
        $event->publish(true);
        $relation = $this->relation($event);
        $this->app->config[ProgramSeal::CONFIG_KEY] = (new SealBuilder)->reset($event->owner)->fillRequiredProperties()->save()->getInstance()->id;

        $this->login($this->userDirector->createUser());
        $seals = (new ApiQuery(Seal::class, ['@select' => 'id']))->find();
        $this->assertContains($this->festivitySeal()->id, array_column($seals, 'id'), 'Quem não é admin vê o selo dos festejos');

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('seal', 'sealRelation', [$relation->id]), false);
        $this->assertSame(200, $this->app->response->getStatusCode(), 'O certificado abre');
    }
}
