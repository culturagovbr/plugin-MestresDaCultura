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

    // A situação do festejo muda só pelas rotas próprias (Publicar, que confere as 6 etapas).
    function testTheStatusChangesOnlyThroughThePublishRoute()
    {
        $event = $this->draft();
        $status = fn (): int => (int) $this->app->conn->fetchOne('SELECT status FROM event WHERE id = ?', [$event->id]);

        $this->call($this->requestFactory->PATCH_entity($event, ['status' => Event::STATUS_ENABLED, 'name' => 'Samba de Roda']));
        $this->assertSame(Event::STATUS_DRAFT, $status(), 'PATCH não publica');
        $this->assertSame('Samba de Roda', $this->app->conn->fetchOne('SELECT name FROM event WHERE id = ?', [$event->id]), 'O resto da edição segue');

        // O PUT, montado a partir do PATCH.
        [$code] = $this->call($this->requestFactory->PATCH_entity($event, ['status' => Event::STATUS_ENABLED, 'name' => 'Samba de Roda no PUT'])->withMethod('PUT'));
        $this->assertSame(200, $code, 'O PUT grava');
        $this->assertSame(Event::STATUS_DRAFT, $status(), 'Nem PUT publica');

        [$code] = $this->call($this->requestFactory->POST('event', 'single', [$event->id], ['status' => Event::STATUS_ENABLED, 'name' => 'Samba de Roda no POST']));
        $this->assertSame(200, $code, 'O POST na entidade grava');
        $this->assertSame(Event::STATUS_DRAFT, $status(), 'Nem POST na entidade publica');
        $this->assertSame('Samba de Roda no POST', $this->app->conn->fetchOne('SELECT name FROM event WHERE id = ?', [$event->id]));
    }
}
