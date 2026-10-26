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

    private ?array $logHandlers = null;

    // O log só no TestHandler: impresso na saída de erro, ele derruba o teste antes das asserções. O log é do processo
    // inteiro: os destinos voltam no tearDown.
    private function captureLog(): TestHandler
    {
        $log = new TestHandler();
        $this->logHandlers = $this->app->log->getHandlers();
        $this->app->log->setHandlers([$log]);

        return $log;
    }

    protected function tearDown(): void
    {
        if ($this->logHandlers !== null) {
            $this->app->log->setHandlers($this->logHandlers);
        }

        parent::tearDown();
    }

    // A tela de cadastro indisponível, com o contato do suporte, e nenhum cadastro criado.
    private function assertUnavailablePage(int $status, string $message): void
    {
        $body = (string) $this->app->response->getBody();

        $this->assertEquals(200, $status, $message);
        $this->assertStringContainsString('Cadastro indisponível', $body, $message);
        $this->assertStringContainsString('suporte.mapa@cultura.gov.br', $body, 'O aviso precisa trazer o contato do suporte');
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
        $this->captureLog();
        $this->login($this->userDirector->createUser());
        $this->configure(null);

        [$status] = $this->visit();

        $this->assertUnavailablePage($status, 'Sem oportunidade configurada, a mestra vê o aviso');
    }

    function testRefusesWhenTheRegistrationPeriodIsClosed()
    {
        $log = $this->captureLog();
        $opportunity = $this->createOpportunity(new Past);
        $this->configure($opportunity);
        $this->login($this->userDirector->createUser());

        [$status] = $this->visit();

        $this->assertUnavailablePage($status, 'Com o período fechado, a mestra vê o aviso');
        $this->assertEmpty($this->registrationsOf($this->app->user, $opportunity), 'Período fechado não permite criar inscrição');
        $this->assertTrue(
            $log->hasErrorThatContains('período está fechado'),
            'O motivo da recusa precisa ficar evidente no log'
        );
    }

    function testRefusesWhenTheOpportunityIsNotPublished()
    {
        $log = $this->captureLog();
        $opportunity = $this->createOpportunity();
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->save(true);

        $this->configure($opportunity);
        $this->login($this->userDirector->createUser());

        [$status] = $this->visit();

        $this->assertUnavailablePage($status, 'Com a oportunidade em rascunho, a mestra vê o aviso');
        $this->assertEmpty($this->registrationsOf($this->app->user, $opportunity), 'Oportunidade em rascunho não pode receber cadastro');
        $this->assertTrue(
            $log->hasErrorThatContains('não está publicada'),
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

    // A trava do servidor, independente da rota: quem monta a requisição à mão não pode
    // conseguir um segundo cadastro.
    private function postRegistration(Opportunity $opportunity, $owner): int
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->POST('registration', 'index', [], [
            'opportunityId' => $opportunity->id,
            'ownerId' => $owner->id,
        ]), false);

        return $this->app->response->getStatusCode();
    }

    function testASecondRegistrationIsRefusedEvenBypassingTheRoute()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        $status = $this->postRegistration($opportunity, $user->profile);

        $this->assertEquals(400, $status, 'A segunda inscrição por requisição direta precisa ser recusada');
        $this->assertCount(
            1,
            $this->registrationsOf($user, $opportunity),
            'A segunda inscrição não pode chegar ao banco'
        );
    }

    function testASecondRegistrationIsRefusedForAnotherAgentOfTheSameUser()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        $second = $this->agentBuilder->reset($user)->fillRequiredProperties()->save()->getInstance();

        $this->assertEquals(
            400,
            $this->postRegistration($opportunity, $second),
            'Trocar de agente não pode contornar a regra: a contagem é por pessoa'
        );
    }

    // A trava é do programa, e o plugin roda na instalação inteira: não pode alcançar
    // inscrição de outra oportunidade, sob pena de quebrar os demais subsites.
    function testTheGuardDoesNotReachOtherOpportunities()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->configure($fixed);

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        $this->assertEquals(
            200,
            $this->postRegistration($other, $user->profile),
            'Ter cadastro no programa não pode impedir inscrição em outra oportunidade'
        );
    }

    function testARegistrationInTheTrashDoesNotBlockANewOne()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->visit();

        // Inscrição não tem exclusão suave: o setter recusa o status de lixeira
        // (Registration.php:1133), e esse estado só chega ao banco por escrita direta.
        $registration = $this->registrationsOf($user, $opportunity)[0];
        $this->app->em->createQuery(
            'UPDATE MapasCulturais\Entities\Registration r SET r.status = :trash WHERE r.id = :id'
        )
            ->setParameter('trash', Registration::STATUS_TRASH)
            ->setParameter('id', $registration->id)
            ->execute();

        $this->assertEquals(
            200,
            $this->postRegistration($opportunity, $user->profile),
            'Cadastro na lixeira não conta: a pessoa precisa poder recomeçar'
        );
    }

    function testTheFirstRegistrationByDirectRequestIsAllowed()
    {
        $opportunity = $this->createOpportunity();
        $this->configure($opportunity);

        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertEquals(
            200,
            $this->postRegistration($opportunity, $user->profile),
            'A trava não pode barrar quem ainda não tem cadastro'
        );
        $this->assertCount(
            1,
            $this->registrationsOf($user, $opportunity),
            'E a inscrição precisa ter sido criada de fato'
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
