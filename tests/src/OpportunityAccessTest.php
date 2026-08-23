<?php

namespace Tests\MDC;

use MapasCulturais\Entities\AgentOpportunity;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class OpportunityAccessTest extends TestCase
{
    use RequestFactory;
    use UserDirector;

    private function newOpportunityOwnedBy($user): Opportunity
    {
        $opportunity = new AgentOpportunity;
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;

        return $opportunity;
    }

    function testRegularUserCannotCreateOpportunity()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertFalse(
            $this->newOpportunityOwnedBy($user)->canUser('create'),
            'Usuário comum não pode criar oportunidade neste tema'
        );
    }

    function testAdminCannotCreateOpportunityEither()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $this->assertFalse(
            $this->newOpportunityOwnedBy($admin)->canUser('create'),
            'A oportunidade do programa nasce em outro subsite: nem admin cria por aqui'
        );
    }

    function testSaasSuperAdminCanCreateOpportunity()
    {
        $user = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($user);

        $this->assertTrue(
            $this->newOpportunityOwnedBy($user)->canUser('create'),
            'O super administrador do SaaS não sofre o bloqueio de criação'
        );
    }

    private function panelOpportunitiesActions(): string
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('panel', 'opportunities'), false);

        $body = $this->app->response->getBody();
        $body->rewind();

        // O rótulo do botão também vive no template do componente, que vai na página inteira
        // assim que ele é importado; só o trecho de ações do cabeçalho distingue os papéis.
        preg_match('#panel-page__header-actions">(.*?)</div>#s', $body->getContents(), $matches);
        $actions = $matches[1] ?? '';

        $this->assertStringContainsString(
            'opportunity-importer',
            $actions,
            'O trecho de ações do cabeçalho precisa ter sido extraído, senão a asserção passa à toa'
        );

        return $actions;
    }

    function testCreateButtonIsRenderedForSaasSuperAdmin()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertStringContainsString(
            '<create-opportunity',
            $this->panelOpportunitiesActions(),
            'O super administrador do SaaS precisa do botão de criar oportunidade'
        );
    }

    function testCreateButtonIsHiddenForAdmin()
    {
        $this->login($this->userDirector->createUser('admin'));

        $this->assertStringNotContainsString(
            '<create-opportunity',
            $this->panelOpportunitiesActions(),
            'Nenhum outro papel cria oportunidade por aqui'
        );
    }

    function testOpportunityBrowsingIsBlockedForRegularUser()
    {
        $this->login($this->userDirector->createUser());

        foreach (['search', 'panel'] as $controller) {
            $this->assertStatus404(
                $this->requestFactory->GET($controller, 'opportunities'),
                "A listagem de oportunidades em {$controller} deve recusar usuário comum: o tema roda sobre uma oportunidade fixa"
            );
        }
    }

    function testOpportunityBrowsingIsAllowedForAdmin()
    {
        $this->login($this->userDirector->createUser('admin'));

        $this->assertStatus200(
            $this->requestFactory->GET('search', 'opportunities'),
            'A listagem de oportunidades segue acessível a administrador'
        );
    }

    function testRegistrationListRemainsAccessible()
    {
        $this->login($this->userDirector->createUser());

        $this->assertStatus200(
            $this->requestFactory->GET('panel', 'registrations'),
            'A lista de inscrições do usuário não pode ser afetada pelo bloqueio'
        );
    }

    function testOpportunityRemainsEnabled()
    {
        $this->login($this->userDirector->createUser());

        $this->assertTrue(
            $this->app->isEnabled('opportunities'),
            'A entidade precisa seguir habilitada: as inscrições dependem dela'
        );

        $this->assertStatus404(
            $this->requestFactory->GET('search', 'opportunities'),
            'Habilitada não significa navegável: a entidade fica ligada para as inscrições, sem listagem'
        );
    }
}
