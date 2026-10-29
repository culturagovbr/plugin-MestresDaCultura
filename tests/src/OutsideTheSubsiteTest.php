<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Fora do subsite dos Mestres, o plugin não mexe no core: cada caso confere que o gancho age com o tema dos Mestres e
 * não age fora dele.
 */
class OutsideTheSubsiteTest extends TestCase
{
    use OutsideTheSubsite;
    use UserDirector;

    /** O gancho termina a requisição (redireciona ou dá 404)? */
    private function stops(callable $apply): bool
    {
        // Cria a resposta em que o redirecionamento escreve.
        $this->app->response = new \Laminas\Diactoros\Response();

        try {
            $apply();
        } catch (\MapasCulturais\Exceptions\Halt | \MapasCulturais\Exceptions\NotFound) {
            return true;
        }

        return false;
    }

    function testTheEventSearchIsNotRedirected()
    {
        $apply = fn () => $this->stops(fn () => $this->app->applyHookBoundTo($this->app->controller('search'), 'GET(search.events):before'));

        $this->assertTrue($apply(), 'No subsite dos Mestres, a busca de eventos leva aos festejos');
        $this->assertFalse($this->outsideTheSubsite($apply), 'Fora dele, segue a do core');
    }

    function testAppsAreNotClosed()
    {
        $apply = fn () => $this->stops(fn () => $this->app->applyHookBoundTo($this->app->controller('app'), 'ALL(app.index):before'));

        $this->assertTrue($apply(), 'No subsite dos Mestres, os apps ficam fechados');
        $this->assertFalse($this->outsideTheSubsite($apply), 'Fora dele, abertos');
    }

    function testAnEventIsNotAskedForTheSixSteps()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $errors = function () use ($user) {
            $event = new Event;
            $event->name = 'Evento';
            $event->owner = $user->profile;
            $event->status = Event::STATUS_ENABLED;

            return $event->getValidationErrors();
        };

        $this->assertArrayHasKey(FestivityFields::SIZE, $errors(), 'No subsite dos Mestres, o evento publicado é festejo e cobra as etapas');
        $this->assertArrayNotHasKey(FestivityFields::SIZE, $this->outsideTheSubsite($errors), 'Fora dele, é evento do core');
    }

    function testTheEventStatusIsEditedAsInTheCore()
    {
        $apply = function () {
            $data = ['status' => Event::STATUS_ENABLED, 'name' => 'Evento'];
            $this->app->applyHookBoundTo($this->app->controller('event'), 'PATCH(event.single):data', ['data' => &$data]);

            return $data;
        };

        $this->assertArrayNotHasKey('status', $apply(), 'No subsite dos Mestres, publicar é pela rota própria');
        $this->assertArrayHasKey('status', $this->outsideTheSubsite($apply), 'Fora dele, o PATCH do core muda a situação');
    }
}
