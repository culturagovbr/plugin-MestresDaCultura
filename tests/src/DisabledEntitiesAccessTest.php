<?php

namespace Tests\MDC;

use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\SealBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class DisabledEntitiesAccessTest extends TestCase
{
    use CapturesLog;
    use RequestFactory;
    use UserDirector;

    private const DISABLED = ['agents', 'spaces', 'projects'];

    private function assertOpens($request, string $message): void
    {
        $this->app->reset();
        $this->app->run($request, false);

        $this->assertSame(200, $this->app->response->getStatusCode(), $message);
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

    // O gestor do subsite (admin) segue com os agentes, sem espaços e projetos.
    function testTheManagerKeepsOnlyTheAgents()
    {
        $this->login($this->userDirector->createUser('admin'));

        $this->assertStatus200($this->requestFactory->GET('search', 'agents'), 'A busca de agentes segue com o gestor');

        foreach (['spaces', 'projects'] as $entity) {
            $this->assertStatus404($this->requestFactory->GET('search', $entity), "A busca de {$entity} recusa o gestor");
            $this->assertStatus404($this->requestFactory->GET('panel', $entity), "A lista de {$entity} no painel recusa o gestor");
        }

        foreach (['space', 'project'] as $controller) {
            $this->assertStatus404($this->requestFactory->GET($controller, 'create'), "A criação de {$controller} recusa o gestor");
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

    // A criação, a alteração e a remoção de projeto ficam fechadas, mesmo para o projeto da própria pessoa.
    function testWriteOnProjectIsBlockedForRegularUser()
    {
        $user = $this->userDirector->createUser();
        $this->app->disableAccessControl();
        $project = new \MapasCulturais\Entities\Project;
        $project->name = 'Projeto da pessoa';
        $project->shortDescription = 'Projeto';
        $project->type = 1;
        $project->owner = $user->profile;
        $project->save(true);
        $this->app->enableAccessControl();
        $this->login($user);

        $this->assertStatus404(
            $this->requestFactory->POST('project', 'index', [], ['name' => 'Teste']),
            'A criação de project por requisição direta deve recusar usuário comum'
        );

        $this->assertStatus404(
            $this->requestFactory->createServerRequest('PATCH', 'project', 'single', [$project->id], [], [], [], ['name' => 'Teste']),
            'A alteração do próprio project por requisição direta deve recusar usuário comum'
        );

        $this->assertStatus404(
            $this->requestFactory->createServerRequest('DELETE', 'project', 'single', [$project->id]),
            'A remoção do próprio project por requisição direta deve recusar usuário comum'
        );

        $this->assertSame('Projeto da pessoa', $this->app->conn->fetchOne('SELECT name FROM project WHERE id = ?', [$project->id]), 'Nada mudou');
        $this->assertSame(1, (int) $this->app->conn->fetchOne('SELECT status FROM project WHERE id = ?', [$project->id]));
    }

    function testWriteOnAgentRemainsOpen()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertOpens(
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

    // Só a página (single) do agente com o selo do programa abre para quem não é admin.
    function testOnlyThePageOfAMasterWithTheProgramSealIsPublic()
    {
        // Guarda o aviso de log da página sem a oportunidade do programa.
        $this->captureLog();
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;

        $master = $this->userDirector->createUser()->profile;
        $other = $this->userDirector->createUser()->profile;
        $this->app->disableAccessControl();
        $master->createSealRelation($seal, true, true);
        $this->app->enableAccessControl();
        // O usuário comum segue a mesma regra do visitante.
        $this->login($this->userDirector->createUser());

        $this->assertStatus200($this->requestFactory->GET('agent', 'single', [$master->id]), 'A página do mestre com o selo abre para todos');
        $this->assertStatus404($this->requestFactory->GET('agent', 'edit', [$master->id]), 'A edição não');
        $this->assertStatus404($this->requestFactory->GET('agent', 'single', [$other->id]), 'Nem a página de quem não tem o selo');
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

    // As telas de espaços não abrem no subsite, nem para o saasSuperAdmin.
    function testSpacesAreClosedEvenForTheSaasSuperAdmin()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertStatus404($this->requestFactory->GET('search', 'spaces'), 'A busca de espaços recusa o saasSuperAdmin');
        $this->assertStatus404($this->requestFactory->GET('panel', 'spaces'), 'A lista de espaços no painel recusa o saasSuperAdmin');
        $this->assertStatus404($this->requestFactory->GET('space', 'create'), 'A criação de espaço recusa o saasSuperAdmin');
    }

    function testAgentsAndProjectsRemainOpenForSaasSuperAdmin()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        foreach (['agents', 'projects'] as $entity) {
            $this->assertStatus200(
                $this->requestFactory->GET('search', $entity),
                "A busca de {$entity} deve liberar o super administrador do SaaS"
            );

            $this->assertStatus200(
                $this->requestFactory->GET('panel', $entity),
                "A lista de {$entity} no painel deve liberar o super administrador do SaaS"
            );
        }
    }

    function testEnabledEntitiesRemainAccessible()
    {
        $this->login($this->userDirector->createUser());

        $this->assertTrue(
            $this->app->isEnabled('events'),
            'A entidade events precisa estar habilitada, senão este teste não prova nada'
        );

        // No subsite, a busca de eventos leva à de festejos.
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('search', 'events'), false);
        $this->assertSame(
            $this->app->createUrl('mdc', 'festivities'),
            $this->app->response->getHeaderLine('Location'),
            'A busca de eventos não deve ser afetada pelo bloqueio'
        );
    }
}
