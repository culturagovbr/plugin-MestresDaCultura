<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Space;
use MDC\Services\FestivityPlace;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Quem tem conta cria e edita o próprio local do festejo por POST space/index e PATCH space/single; os espaços
// seguem sem busca, painel nem página no subsite dos Mestres.
class FestivityPlaceTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    // Só o que o formulário do festejo pede: sem descrição curta e sem tipo.
    private const PLACE = [
        'name' => 'Praça da Aclamação',
        'En_CEP' => '44300-000',
        'En_Nome_Logradouro' => 'Praça da Aclamação',
        'En_Num' => 's/n',
        'En_Bairro' => 'Centro',
        'En_Municipio' => 'Cachoeira',
        'En_Estado' => 'BA',
        'location' => ['latitude' => -12.6181, 'longitude' => -38.9558],
    ];

    /** @return array{0: int, 1: mixed} status e corpo (JSON decodificado) */
    private function call(ServerRequestInterface $request): array
    {
        $this->app->reset();
        $this->app->run($request, false);
        $body = (string) $this->app->response->getBody();

        return [$this->app->response->getStatusCode(), json_decode($body, true) ?? $body];
    }

    private function createPlace(): Space
    {
        [$status, $body] = $this->call($this->requestFactory->POST('space', 'index', payload: self::PLACE));
        $this->assertSame(200, $status, json_encode($body, JSON_UNESCAPED_UNICODE));

        return $this->app->repo('Space')->find($body['id']);
    }

    function testTheRealizerCreatesThePlaceWithTheAddress()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $place = $this->createPlace();

        $this->assertSame($user->profile->id, $place->owner->id);
        $this->assertSame(FestivityPlace::type(), $place->type->id, 'O tipo vem sozinho');
        $this->assertSame('44300-000', $place->En_CEP);
        $this->assertSame('Cachoeira', $place->En_Municipio);
        $this->assertSame('BA', $place->En_Estado);
        $this->assertEqualsWithDelta(-12.6181, (float) $place->location->latitude, 0.0001);
    }

    function testOnlyTheOwnerEditsThePlace()
    {
        $this->login($this->userDirector->createUser());
        $place = $this->createPlace();

        [$status] = $this->call($this->requestFactory->PATCH_entity($place, ['name' => 'Praça Teixeira de Freitas']));
        $this->assertSame(200, $status, 'A dona edita');

        $this->login($this->userDirector->createUser());
        [$status] = $this->call($this->requestFactory->PATCH_entity($place, ['name' => 'Outro nome']));
        $this->assertSame(403, $status, 'Outra pessoa não edita: a regra do core segue');

        $this->app->em->clear();
        $this->assertSame('Praça Teixeira de Freitas', $this->app->repo('Space')->find($place->id)->name);
    }

    function testThePlaceHasNoScreenOfItsOwn()
    {
        $this->login($this->userDirector->createUser());
        $place = $this->createPlace();

        $this->assertStatus404($this->requestFactory->GET('space', 'single', [$place->id]), 'A página do espaço não abre nem para a dona');
        $this->assertStatus404($this->requestFactory->GET('space', 'edit', [$place->id]), 'A edição do core não abre');
        $this->assertStatus404($this->requestFactory->GET('panel', 'spaces'), 'O painel segue sem espaços');

        $this->logout();
        $this->assertStatus404($this->requestFactory->GET('space', 'single', [$place->id]), 'Nem para o visitante');
        $this->assertStatus404($this->requestFactory->POST('space', 'index', payload: self::PLACE), 'O visitante não grava');
    }

    // O local nasce em rascunho, é publicado quando um festejo publicado o usa e volta a rascunho quando nenhum usa
    // mais.
    function testThePlaceFollowsTheFestivity()
    {
        $this->login($this->userDirector->createUser());
        $place = $this->createPlace();
        // Lê a situação direto no banco, sem limpar o EntityManager.
        $status = fn (): int => (int) $this->app->conn->fetchOne('SELECT status FROM space WHERE id = ?', [$place->id]);

        $this->assertSame(Space::STATUS_DRAFT, $status(), 'Nasce em rascunho');

        $event = $this->draft($this->app->repo('Space')->find($place->id)->owner->user);
        $occurrence = $this->period($event, $this->app->repo('Space')->find($place->id));
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'O festejo em rascunho não publica o local');

        $this->app->repo('Event')->find($event->id)->publish(true);
        $this->assertSame(Space::STATUS_ENABLED, $status(), 'Publicado com o festejo');

        $this->app->repo('Event')->find($event->id)->unpublish(true);
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'Volta a rascunho com ele');

        $this->app->repo('Event')->find($event->id)->publish(true);
        $this->app->repo('EventOccurrence')->find($occurrence->id)->delete(true);
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'Sem período nele, volta a rascunho');
    }

    function testAPlaceInUseIsNotDeleted()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $place = $this->createPlace();
        $event = $this->draft($user);
        $occurrence = $this->period($event, $this->app->repo('Space')->find($place->id));
        $delete = fn () => $this->call($this->requestFactory->createServerRequest('DELETE', 'space', 'single', [$place->id]));

        $this->login($this->userDirector->createUser());
        [$status] = $delete();
        $this->assertSame(403, $status, 'Outra pessoa não exclui');

        $this->login($user);
        [$status, $body] = $delete();
        $this->assertSame(400, $status, 'Em uso por um festejo, não sai');
        $this->assertStringContainsString($event->name, json_encode($body, JSON_UNESCAPED_UNICODE), 'O erro diz qual festejo usa o local');
        $this->app->em->clear();
        $this->assertSame(Space::STATUS_DRAFT, $this->app->repo('Space')->find($place->id)->status);

        $this->app->repo('EventOccurrence')->find($occurrence->id)->delete(true);
        [$status] = $delete();
        $this->assertContains($status, [200, 302], 'Sem festejo, a dona exclui (fora do AJAX o core redireciona)');
        $this->app->em->clear();
        $this->assertSame(Space::STATUS_TRASH, $this->app->repo('Space')->find($place->id)->status);
    }

    // "Meus locais" lista só os locais da pessoa fora da lixeira, com os festejos que os usam.
    function testMyPlacesListsOnlyMyPlacesWithTheirFestivities()
    {
        $user = $this->userDirector->createUser();
        $event = $this->draft($user);
        $used = $this->place($event, 'Cachoeira', 'BA');
        $this->period($event, $used);
        $free = $this->place($event, 'Recife', 'PE');
        $trashed = $this->place($event, 'Natal', 'RN');
        $trashed->delete(true);

        $other = $this->draft();
        $this->place($other, 'Belém', 'PA');

        $places = array_column(FestivityPlace::ofUser($this->app->repo('User')->find($user->id)), null, 'id');

        $this->assertEqualsCanonicalizing([$used->id, $free->id], array_keys($places), 'Só os da pessoa, fora da lixeira');
        $this->assertSame([['id' => $event->id, 'name' => $event->name, 'status' => Event::STATUS_DRAFT]], $places[$used->id]['festivities']);
        $this->assertSame([], $places[$free->id]['festivities']);
        $this->assertSame('Recife', $places[$free->id]['city']);
    }

    function testMyPlacesNeedsAnAccount()
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'places'), false);
        $this->assertContains($this->app->response->getStatusCode(), [302, 401], 'Sem conta, vai para o login');

        $this->login($this->userDirector->createUser());
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'places'), false);
        $this->assertSame(200, $this->app->response->getStatusCode());
    }

    // Com o local do período excluído, a etapa 2 cobra a troca, sem impedir o rascunho.
    function testAPeriodWithARemovedPlaceIsAskedAgain()
    {
        $event = $this->draft();
        $place = $this->place($event);
        $this->period($event, $place);
        $this->assertArrayNotHasKey('removedPlace', \MDC\Services\FestivityForm::missing($event, 1));

        $place->delete(true);
        $event = $this->app->repo('Event')->find($event->id);

        $this->assertArrayHasKey('removedPlace', \MDC\Services\FestivityForm::missing($event, 1), 'A etapa 2 cobra o local');
        $this->assertArrayNotHasKey('removedPlace', $event->getValidationErrors(), 'O rascunho ainda salva');
    }

    // O local de outra pessoa não entra na agenda, e o período pendente nele não publica nem prende o local.
    function testAnotherPersonsPlaceStaysOutOfTheAgenda()
    {
        $owner = $this->userDirector->createUser();
        $this->login($owner);
        $place = $this->createPlace();

        $event = $this->draft();
        $occurrence = new \MapasCulturais\Entities\EventOccurrence;
        $occurrence->event = $event;
        $occurrence->space = $this->app->repo('Space')->find($place->id);
        $occurrence->rule = ['startsOn' => '2026-12-03', 'startsAt' => '18:00', 'duration' => 300, 'frequency' => 'once'];
        $this->assertArrayHasKey('space', $occurrence->getValidationErrors(), 'Só o próprio local');

        $this->app->disableAccessControl();
        $occurrence->status = \MapasCulturais\Entities\EventOccurrence::STATUS_PENDING;
        $occurrence->save(true);
        $this->app->enableAccessControl();
        $this->app->repo('Event')->find($event->id)->publish(true);

        $status = fn (): int => (int) $this->app->conn->fetchOne('SELECT status FROM space WHERE id = ?', [$place->id]);
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'O pendente não publica o local');
        $this->assertSame([], FestivityPlace::eventsAt($place->id), 'Nem prende a exclusão');
    }

    // A situação do local não muda pelo PATCH nem pelo PUT, em qualquer caixa de letra; o resto da edição segue.
    function testTheStatusIsNotEditedDirectly()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $place = $this->createPlace();
        $this->period($this->draft($user), $this->app->repo('Space')->find($place->id));
        $status = fn (): int => (int) $this->app->conn->fetchOne('SELECT status FROM space WHERE id = ?', [$place->id]);

        $this->call($this->requestFactory->PATCH_entity($place, ['status' => Space::STATUS_TRASH]));
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'PATCH não manda o local em uso para a lixeira');

        $this->call($this->requestFactory->PATCH_entity($place, ['status' => Space::STATUS_ENABLED, 'name' => 'Praça nova']));
        $this->assertSame(Space::STATUS_DRAFT, $status(), 'Nem o publica sem festejo');
        $this->assertSame('Praça nova', $this->app->conn->fetchOne('SELECT name FROM space WHERE id = ?', [$place->id]), 'O resto da edição segue');
    }
}

