<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityForm;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

// As etapas 1 e 2: um segmento e até duas tipologias dele (o "Outro" conta como uma), mesmo no rascunho; a UF, a
// cidade e a região saem do primeiro local da agenda; a lei própria pede o arquivo.
class FestivityStepsTest extends TestCase
{
    use BuildsFestivities;
    use UserDirector;

    private function classify(Event $event, array $typologies, ?string $other = null): array
    {
        $event->{FestivityFields::SEGMENT} = 'Celebrações e Rituais';
        $event->{FestivityFields::TYPOLOGIES} = $typologies;
        $event->{FestivityFields::OTHER_TYPOLOGY} = $other;

        return $event->getValidationErrors();
    }

    function testTheClassificationIsOneSegmentAndUpToTwoOfItsTypologies()
    {
        $event = $this->draft();

        $this->assertArrayNotHasKey(FestivityFields::TYPOLOGIES, $this->classify($event, ['Carnaval', 'Festas do Ciclo Junino']));
        $this->assertArrayHasKey(FestivityFields::TYPOLOGIES, $this->classify($event, ['Carnaval', 'Festas do Ciclo Junino', 'Festas de Santos/Devocional']), 'Três não');
        $this->assertArrayHasKey(FestivityFields::TYPOLOGIES, $this->classify($event, ['Carnaval'], 'Festa do Divino', ) + $this->classify($event, ['Carnaval', 'Festas do Ciclo Junino'], 'Festa do Divino'), 'O "Outro" conta como uma');
        $this->assertArrayHasKey(FestivityFields::TYPOLOGIES, $this->classify($event, ['Festas Étnico-Culturais']), 'Tipologia de outro segmento');

        $this->classify($event, [], 'Festa do Divino');
        $this->assertArrayNotHasKey(FestivityFields::TYPOLOGIES, FestivityForm::missing($event, 0), 'Só o "Outro" já classifica');
    }

    function testUfCityAndRegionComeFromTheFirstPlace()
    {
        $event = $this->draft();
        $aracaju = $this->period($event, $this->place($event, 'Aracaju', 'SE'), '2026-12-10');
        $this->period($event, $this->place($event, 'Cachoeira', 'BA'), '2026-12-03');

        $this->app->em->refresh($event);
        $this->assertSame(['BA', 'Cachoeira', 'Nordeste'], [$event->{FestivityFields::UF}, $event->{FestivityFields::CITY}, $event->{FestivityFields::REGION}], 'O primeiro período, pela data');

        $place = $this->place($event, 'Belém', 'PA');
        $aracaju->delete(true);
        $this->period($event, $place, '2026-11-20');
        $this->app->em->refresh($event);
        $this->assertSame(['PA', 'Belém', 'Norte'], [$event->{FestivityFields::UF}, $event->{FestivityFields::CITY}, $event->{FestivityFields::REGION}]);

        $place->En_Municipio = 'Ananindeua';
        $place->save(true);
        $this->app->em->refresh($event);
        $this->assertSame('Ananindeua', $event->{FestivityFields::CITY}, 'Mudar o endereço do local muda o festejo');
    }

    function testAPlaceWithoutStateOrCityAndALawWithoutFileAreMissing()
    {
        $event = $this->draft();
        $this->period($event, $this->place($event, '', ''));
        $event->{FestivityFields::LAW} = 'Sim';
        $event->save(true);
        $this->app->em->refresh($event);

        $missing = FestivityForm::missing($event, 1);
        $this->assertArrayHasKey('place', $missing);
        $this->assertArrayHasKey(FestivityFields::LAW_FILE, $missing);

        $event->{FestivityFields::LAW} = 'Não';
        $this->assertArrayNotHasKey(FestivityFields::LAW_FILE, FestivityForm::missing($event, 1));
    }
}
