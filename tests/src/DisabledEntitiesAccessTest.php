<?php

namespace Tests\MDC;

use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class DisabledEntitiesAccessTest extends TestCase
{
    use RequestFactory;
    use UserDirector;

    private const DISABLED = ['agents', 'spaces', 'projects'];

    private function assertHttpStatusCodeIsNot404($request, string $message): void
    {
        $this->app->reset();
        $this->app->run($request, false);

        $this->assertNotEquals(404, $this->app->response->getStatusCode(), $message);
    }

    private function assertEntityIsDisabled(string $entity): void
    {
        $this->assertFalse(
            $this->app->isEnabled($entity),
            "A entidade {$entity} precisa estar desabilitada, senão este teste não prova nada"
        );
    }

    function testSearchOfDisabledEntityIsBlockedForRegularUser()
    {
        $this->login($this->userDirector->createUser());

        foreach (self::DISABLED as $entity) {
            $this->assertEntityIsDisabled($entity);
            $this->assertStatus404(
                $this->requestFactory->GET('search', $entity),
                "A busca de {$entity} deve recusar usuário comum"
            );
        }
    }

    function testSearchOfDisabledEntityIsAllowedForAdmin()
    {
        $this->login($this->userDirector->createUser('admin'));

        foreach (self::DISABLED as $entity) {
            $this->assertStatus200(
                $this->requestFactory->GET('search', $entity),
                "A busca de {$entity} deve liberar administrador"
            );
        }
    }

    function testPanelListOfDisabledEntityIsBlockedForRegularUser()
    {
        $this->login($this->userDirector->createUser());

        foreach (self::DISABLED as $entity) {
            $this->assertStatus404(
                $this->requestFactory->GET('panel', $entity),
                "A lista de {$entity} no painel deve recusar usuário comum"
            );
        }
    }

    function testCreationOfDisabledEntityIsBlockedForRegularUser()
    {
        $this->login($this->userDirector->createUser());

        foreach (['space', 'project'] as $controller) {
            $this->assertStatus404(
                $this->requestFactory->GET($controller, 'create'),
                "A criação de {$controller} deve recusar usuário comum"
            );
        }
    }

    function testWriteOnSpaceAndProjectIsBlockedForRegularUser()
    {
        $this->login($this->userDirector->createUser());

        foreach (['space', 'project'] as $controller) {
            $this->assertStatus404(
                $this->requestFactory->POST($controller, 'index', [], ['name' => 'Teste']),
                "A criação de {$controller} por requisição direta deve recusar usuário comum"
            );

            $this->assertStatus404(
                $this->requestFactory->createServerRequest('PATCH', $controller, 'single', [1], [], [], [], ['name' => 'Teste']),
                "A alteração de {$controller} por requisição direta deve recusar usuário comum"
            );

            $this->assertStatus404(
                $this->requestFactory->createServerRequest('DELETE', $controller, 'single', [1]),
                "A remoção de {$controller} por requisição direta deve recusar usuário comum"
            );
        }
    }

    function testWriteOnAgentRemainsOpen()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertHttpStatusCodeIsNot404(
            $this->requestFactory->createServerRequest('PATCH', 'agent', 'single', [$user->profile->id], [], [], [], ['name' => 'Teste']),
            'A escrita em agente segue aberta: é por ela que o usuário edita o próprio perfil'
        );
    }

    function testOtherUserAgentPageIsBlockedForRegularUser()
    {
        $other = $this->userDirector->createUser();
        $this->login($this->userDirector->createUser());

        $this->assertStatus404(
            $this->requestFactory->GET('agent', 'single', [$other->profile->id]),
            'A página de agente de terceiro deve recusar usuário comum'
        );
    }

    function testOwnProfileRemainsAccessible()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertStatus200(
            $this->requestFactory->GET('agent', 'single', [$user->profile->id]),
            'O usuário deve seguir acessando o próprio perfil, do qual dependem as inscrições'
        );

        $this->assertStatus200(
            $this->requestFactory->GET('agent', 'edit', [$user->profile->id]),
            'O usuário deve seguir editando o próprio perfil'
        );
    }

    function testEnabledEntitiesRemainAccessible()
    {
        $this->login($this->userDirector->createUser());

        foreach (['opportunities', 'events'] as $entity) {
            $this->assertTrue(
                $this->app->isEnabled($entity),
                "A entidade {$entity} precisa estar habilitada, senão este teste não prova nada"
            );
            $this->assertStatus200(
                $this->requestFactory->GET('search', $entity),
                "A busca de {$entity} não deve ser afetada pelo bloqueio"
            );
        }
    }
}
