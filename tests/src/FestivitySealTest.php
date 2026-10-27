<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Seal;
use MDC\Services\FestivityFields;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// FJ-07: publicar põe o festejo no Calendário com o selo dos festejos, que abre o certificado; despublicar tira o
// selo, e publicar de novo mantém a data da primeira publicação, porque é desde ela que o festejo está no Calendário.
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

    function testPublishingAppliesTheSealAndUnpublishingRemovesIt()
    {
        $event = $this->draft();
        $this->assertNull($this->relation($event), 'O rascunho não tem selo');

        $event->publish(true);
        $this->assertNotNull($this->relation($event), 'Publicado, tem o selo');

        $event->unpublish(true);
        $this->assertNull($this->relation($event), 'Despublicado, não tem mais');
    }

    function testPublishingAgainKeepsTheFirstDate()
    {
        $event = $this->draft();
        $event->publish(true);
        $event->unpublish(true);

        // Primeira publicação no ano passado.
        $event->{FestivityFields::FIRST_PUBLISHED} = '2025-12-01 10:00:00';
        $event->save(true);
        $event->publish(true);

        $this->assertSame('2025-12-01', $this->relation($event)->createTimestamp->format('Y-m-d'));
    }

    function testTheCertificateIsOpenToEveryone()
    {
        $event = $this->draft();
        $event->publish(true);
        $relation = $this->relation($event);

        $this->login($this->userDirector->createUser());
        $seals = (new ApiQuery(Seal::class, ['@select' => 'id']))->find();
        $this->assertContains($this->festivitySeal()->id, array_column($seals, 'id'), 'Quem não é admin vê o selo dos festejos');

        $this->logout();
        $this->assertStatus200($this->requestFactory->GET('seal', 'sealRelation', [$relation->id]), 'O certificado abre para o visitante');
    }
}
