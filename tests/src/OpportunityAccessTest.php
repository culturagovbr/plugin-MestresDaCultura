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
