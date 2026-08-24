<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use Monolog\Handler\TestHandler;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\PhasePeriods\Past;
use Tests\Traits\AgentBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class RegistrationRouteTest extends TestCase
{
    use AgentBuilder;
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    private function createOpportunity($period = null): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        return $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod($period ?? new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    private function configure(?Opportunity $opportunity): void
    {
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity?->id;
    }

    private function visit(): array
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'index'), false);

        return [
            $this->app->response->getStatusCode(),
            $this->app->response->getHeaderLine('Location'),
        ];
    }

    private function registrationsOf(User $user, Opportunity $opportunity): array
    {
        return $this->app->repo('Registration')->findBy([
            'opportunity' => $opportunity,
            'owner' => $user->profile,
        ]);
    }

    function testGuestIsSentToAuthentication()
    {
        $this->configure($this->createOpportunity());
        $this->app->auth->logout();

        [$status] = $this->visit();

        $this->assertEquals(401, $status, 'Visitante precisa ser mandado à autenticação');
    }

    function testRefusesWhenTheOpportunityIsNotConfigured()
    {
        $this->login($this->userDirector->createUser());
        $this->configure(null);

        [$status] = $this->visit();

        $this->assertEquals(404, $status, 'Sem oportunidade configurada a rota não tem destino');
    }

    function testRefusesWhenTheRegistrationPeriodIsClosed()
    {
        $opportunity = $this->createOpportunity(new Past);
        $this->configure($opportunity);
        $this->login($this->userDirector->createUser());

        $log = new TestHandler();
        $this->app->log->pushHandler($log);

        [$status] = $this->visit();

        $this->assertEquals(404, $status, 'Período fechado não permite criar inscrição');
        $this->assertTrue(
            $log->hasErrorThatContains('período está fechado'),
            'O motivo da recusa precisa ficar evidente no log'
        );
    }

    function testCreatesTheRegistrationWhenTheUserHasNone()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);

        [$status, $location] = $this->visit();

        $registrations = $this->registrationsOf($user, $opportunity);

        $this->assertEquals(302, $status, 'A rota redireciona para o formulário');
        $this->assertCount(1, $registrations, 'Uma inscrição precisa ter sido criada');
        $this->assertEquals($registrations[0]->editUrl, $location, 'O destino é o formulário da inscrição criada');
    }

    function testTheCreatedRegistrationBelongsToTheUserProfile()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        $registration = $this->registrationsOf($user, $opportunity)[0];

        $this->assertEquals(
            $user->profile->id,
            $registration->owner->id,
            'O dono é o agente-perfil, sem o usuário escolher'
        );
    }

    function testReusesTheExistingRegistration()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->visit();
        $first = $this->registrationsOf($user, $opportunity)[0];

        [$status, $location] = $this->visit();

        $this->assertEquals(302, $status);
        $this->assertCount(1, $this->registrationsOf($user, $opportunity), 'Clicar de novo não pode duplicar');
        $this->assertEquals($first->editUrl, $location, 'O segundo clique abre a inscrição existente');
    }

    function testDoesNotReuseTheRegistrationOfAnotherUser()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $other = $this->userDirector->createUser();
        $this->login($other);
        $this->visit();

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        $this->assertCount(
            1,
            $this->registrationsOf($user, $opportunity),
            'A inscrição de terceiro não pode ser confundida com a do usuário'
        );
    }

    // Usuário comum não enxerga inscrição alheia, então a consulta filtraria certo mesmo sem o
    // filtro de usuário. Quem enxerga todas é que revela se o filtro existe.
    function testAPrivilegedUserDoesNotLandOnSomeoneElsesRegistration()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $citizen = $this->userDirector->createUser();
        $this->login($citizen);
        $this->visit();
        $foreign = $this->registrationsOf($citizen, $opportunity)[0];

        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);
        [, $location] = $this->visit();

        $this->assertNotEquals(
            $foreign->editUrl,
            $location,
            'Quem enxerga todas as inscrições não pode cair na de outra pessoa'
        );
        $this->assertCount(
            1,
            $this->registrationsOf($manager, $opportunity),
            'O administrador ganha o próprio cadastro, não o de terceiro'
        );
    }

    function testDoesNotReuseARegistrationOfAnotherOpportunity()
    {
        $other = $this->createOpportunity();
        $fixed = $this->createOpportunity();
        $this->configure($fixed);

        $user = $this->userDirector->createUser();
        $this->login($user);

        $foreign = new Registration;
        $foreign->opportunity = $other;
        $foreign->owner = $user->profile;
        $foreign->save(true);

        $this->visit();

        $this->assertCount(
            1,
            $this->registrationsOf($user, $fixed),
            'Inscrição em outra oportunidade não conta como cadastro do programa'
        );
    }

    function testFindsTheRegistrationOwnedByAnotherAgentOfTheSameUser()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);

        $second = $this->agentBuilder->reset($user)->fillRequiredProperties()->save()->getInstance();

        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $second;
        $registration->save(true);

        [, $location] = $this->visit();

        $this->assertEquals(
            $registration->editUrl,
            $location,
            'A busca é por usuário: agente diferente do mesmo dono não pode gerar segundo cadastro'
        );
    }
}
