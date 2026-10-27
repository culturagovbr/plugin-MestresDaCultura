<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityForm;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// O cadastro do festejo em 6 etapas: o rascunho salva sem cobrar nada, "Salvar e avançar" confere a etapa e a
// publicação confere todas, também pelo Publicar do painel.
class FestivityFormTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    /** @return array{0: int, 1: mixed, 2: string} status, corpo (JSON decodificado) e Location */
    private function call(ServerRequestInterface $request): array
    {
        $this->app->reset();
        $this->app->run($request, false);
        $body = (string) $this->app->response->getBody();

        return [$this->app->response->getStatusCode(), json_decode($body, true) ?? $body, $this->app->response->getHeaderLine('Location')];
    }

    // O que as 6 etapas cobram, como o formulário preencheria.
    private function complete(Event $event): void
    {
        $event->longDescription = 'Todo 4 de dezembro, as sambadeiras do Recôncavo saem em louvor a Santa Bárbara.';
        $event->{FestivityFields::SEGMENT} = 'Celebrações e Rituais';
        $event->{FestivityFields::TYPOLOGIES} = ['Festas de Santos/Devocional'];
        $event->{FestivityFields::SIZE} = 'Médio';
        $event->{FestivityFields::VENUE} = 'Espaço público';
        $event->{FestivityFields::TERRITORY} = 'Comunidade Quilombola';
        $event->{FestivityFields::PRODUCER} = 'Grupo/Coletivo Cultural';
        $event->{FestivityFields::INSTITUTION} = 'Samba de Roda Filhas do Recôncavo';
        $event->{FestivityFields::CONSENT} = '2026-10-27 12:00:00';
        $event->save(true);

        $event->owner->nomeCompleto = 'Celina Pereira dos Santos';
        $event->owner->save(true);

        $this->period($event, $this->place($event));

        $this->app->em->refresh($event);
    }

    function testTheFormHasTheSixStepsOfThePrototype()
    {
        $this->assertSame(
            ['Apresentação do Festejo', 'Localização', 'Impacto Cultural, Social e Econômico', 'Sobre o Realizador', 'Marcadores Sociais', 'Apresentação e LGPD'],
            array_column(FestivityForm::steps(), 'label')
        );
    }

    function testAnyoneWithAnAccountOpensTheForm()
    {
        $this->login($this->userDirector->createUser());
        [$status, $body] = $this->call($this->requestFactory->GET('mdc', 'festivity'));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('<mdc-festivity-form', $body);

        $this->logout();
        $this->assertStatus401($this->requestFactory->GET('mdc', 'festivity'), 'O visitante é mandado entrar');
    }

    function testOnlyWhoEditsTheFestivityContinuesIt()
    {
        $event = $this->draft();
        $this->assertStatus200($this->requestFactory->GET('mdc', 'festivity', [$event->id]), 'A dona continua o rascunho');

        $this->login($this->userDirector->createUser());
        $this->assertStatus403($this->requestFactory->GET('mdc', 'festivity', [$event->id]), 'Outra pessoa não');
        $this->assertStatus403($this->requestFactory->GET('mdc', 'festivityCheck', [$event->id], ['step' => 0], ajax: true), 'Nem confere as etapas');
    }

    function testTheCoreEventScreensLeadToTheForm()
    {
        $event = $this->draft();

        [, , $location] = $this->call($this->requestFactory->GET('event', 'create'));
        $this->assertSame($this->app->createUrl('mdc', 'festivity'), $location, 'O "Criar evento" do core');

        [, , $location] = $this->call($this->requestFactory->GET('event', 'edit', [$event->id]));
        $this->assertSame($this->app->createUrl('mdc', 'festivity', [$event->id]), $location, 'A edição do core');
    }

    function testEachStepAsksOnlyForItsOwnFields()
    {
        $event = $this->draft();
        $check = fn (int $step) => $this->call($this->requestFactory->GET('mdc', 'festivityCheck', [$event->id], ['step' => $step], ajax: true))[1];

        $first = $check(0);
        $this->assertArrayHasKey(FestivityFields::SIZE, $first);
        $this->assertArrayHasKey('longDescription', $first);
        $this->assertArrayNotHasKey('name', $first, 'O nome já está');
        $this->assertArrayNotHasKey(FestivityFields::CONSENT, $first, 'O aceite é da etapa 6');
        $this->assertSame([], (array) $check(2), 'A etapa 3 não tem obrigatórios');

        $this->complete($event);
        $this->assertSame([], (array) $check(0));
    }

    function testADraftSavesIncompleteButPublishingAsksForEveryStep()
    {
        $event = $this->draft();

        [$status] = $this->call($this->requestFactory->PATCH_entity($event, ['name' => 'Samba de Roda de Santa Bárbara (Cachoeira)']));
        $this->assertSame(200, $status, 'O rascunho salva sem o resto');

        [$status, $body] = $this->call($this->requestFactory->POST('event', 'publish', [$event->id]));
        $this->assertSame(400, $status, 'Publicar cobra todas as etapas');
        foreach ([FestivityFields::SIZE, 'occurrences', FestivityFields::TERRITORY, 'responsible', FestivityFields::CONSENT] as $key) {
            $this->assertArrayHasKey($key, $body['data'], "Falta {$key}");
        }

        $this->complete($event);
        [$status] = $this->call($this->requestFactory->POST('event', 'publish', [$event->id]));
        $this->assertSame(200, $status, 'Completo, publica');
        $this->assertSame(Event::STATUS_ENABLED, $this->app->repo('Event')->find($event->id)->status);
    }
}
