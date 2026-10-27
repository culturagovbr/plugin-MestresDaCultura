<?php

namespace Tests\MDC;

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
        $this->assertStatus404($this->requestFactory->createServerRequest('DELETE', 'space', 'single', [$place->id]), 'Nem a remoção');
        $this->assertStatus404($this->requestFactory->GET('panel', 'spaces'), 'O painel segue sem espaços');

        $this->logout();
        $this->assertStatus404($this->requestFactory->GET('space', 'single', [$place->id]), 'Nem para o visitante');
        $this->assertStatus404($this->requestFactory->POST('space', 'index', payload: self::PLACE), 'O visitante não grava');
    }
}
