<?php

namespace Tests\MDC;

use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Cada inscrição e oportunidade abre no subsite dela: a de outro edital, aberta no subsite dos Mestres, vai para o site do
// edital; a do programa e as páginas do programa, abertas em outro subsite, vão para o subsite dos Mestres.
class SubsiteRedirectTest extends TestCase
{
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        return $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    private function registrationOf(User $user, Opportunity $opportunity): Registration
    {
        $this->login($user);

        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $user->profile;
        $registration->save(true);

        return $registration;
    }

    /** Põe a oportunidade num outro subsite da instalação. */
    private function moveToAnotherSite(Opportunity $opportunity): Subsite
    {
        $this->app->disableAccessControl();

        $subsite = new Subsite;
        $subsite->name = 'Outro site';
        $subsite->url = 'outro.localhost';
        $subsite->namespace = 'BaseV2';
        $subsite->owner = $opportunity->owner;
        $subsite->save(true);

        $this->app->em->createQuery('UPDATE MapasCulturais\Entities\Opportunity o SET o._subsiteId = :subsite WHERE o.id = :id')
            ->setParameter('subsite', $subsite->id)
            ->setParameter('id', $opportunity->id)
            ->execute();

        $this->app->enableAccessControl();
        $this->app->em->clear();

        return $subsite;
    }

    /** @return array{int, string} status e Location */
    private function open(User $user, Registration|Opportunity $entity, string $action = 'single'): array
    {
        $controller = $entity instanceof Registration ? 'registration' : 'opportunity';
        $this->login($this->app->repo('User')->find($user->id));
        $this->app->reset();
        $this->app->run($this->requestFactory->GET($controller, $action, [$entity->id]), false);

        return [$this->app->response->getStatusCode(), $this->app->response->getHeaderLine('Location')];
    }

    /** @return int[] ids das oportunidades que a busca devolve, pedida da página indicada */
    private function searchFrom(string $referer): array
    {
        $this->app->reset();
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/opportunity/find',
            queryParams: ['@select' => 'id'],
            headers: ['X-Requested-With' => 'XMLHttpRequest', 'Referer' => $referer],
        );
        $this->app->run($request, false);

        return array_column(json_decode((string) $this->app->response->getBody(), true) ?: [], 'id');
    }

    function testARegistrationOfAnotherSiteOpensThere()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $user = $this->userDirector->createUser();
        $foreign = $this->registrationOf($user, $other);
        $subsite = $this->moveToAnotherSite($other);

        foreach (['single', 'edit'] as $action) {
            [$status, $location] = $this->open($user, $foreign, $action);

            $this->assertContains($status, [301, 302, 303, 307], "A {$action} de outro edital redireciona");
            $this->assertStringStartsWith($subsite->getSubsiteUrl(), $location, "A {$action} vai para o subsite do edital");
            $this->assertStringContainsString((string) $foreign->id, $location, 'Na mesma inscrição');
        }
    }

    function testARegistrationWhoseSiteIsThisOneIsNotFound()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $user = $this->userDirector->createUser();
        $foreign = $this->registrationOf($user, $other);

        [$status] = $this->open($user, $foreign);

        $this->assertEquals(404, $status, 'Sem outro subsite para onde ir, não abre aqui');
    }

    function testTheProgramRegistrationOpensNormally()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $user = $this->userDirector->createUser();
        $own = $this->registrationOf($user, $fixed);

        [$status, $location] = $this->open($user, $own);

        // O rascunho pode redirecionar, mas só dentro do próprio subsite.
        $this->assertContains($status, [200, 302]);
        $this->assertTrue($location === '' || str_starts_with($location, $this->app->baseUrl), 'O cadastro do programa fica no subsite dos Mestres');
    }

    function testTheProgramOpenedOnAnotherSiteGoesToTheMestresSite()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $user = $this->userDirector->createUser();
        $own = $this->registrationOf($user, $fixed);
        $mestres = $this->moveToAnotherSite($fixed);

        foreach ([$this->app->repo('Opportunity')->find($fixed->id), $this->app->repo('Registration')->find($own->id)] as $entity) {
            [$status, $location] = $this->open($user, $entity);

            $this->assertContains($status, [301, 302, 303, 307], 'A página do programa aberta em outro subsite redireciona');
            $this->assertStringStartsWith($mestres->getSubsiteUrl(), $location, 'Para o subsite dos Mestres');
        }
    }

    function testTheProgramIsLeftOutOfTheOpportunitySearchOfOtherSites()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $this->moveToAnotherSite($fixed);
        $this->login($this->userDirector->createUser());

        $fromSearch = $this->searchFrom($this->app->createUrl('search', 'opportunities'));
        $this->assertNotContains($fixed->id, $fromSearch, 'A busca de oportunidades de outro subsite não traz o programa');
        $this->assertContains($other->id, $fromSearch);

        $this->assertNotContains($fixed->id, $this->searchFrom($this->app->createUrl('site', 'index')), 'Nem a home');
        $this->assertContains($fixed->id, $this->searchFrom($this->app->createUrl('panel', 'index')), 'Fora da busca e da home, segue como no core');
    }

    /** @return array{int, string} status e Location */
    private function visit(string $action, array $args = []): array
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', $action, $args), false);

        return [$this->app->response->getStatusCode(), $this->app->response->getHeaderLine('Location')];
    }

    function testTheProgramPagesOpenedOnAnotherSiteGoToTheMestresSite()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $mestres = $this->moveToAnotherSite($fixed);
        $this->login($this->userDirector->createUser());

        foreach ([['masters', []], ['festivities', []], ['places', []], ['festivity', []], ['festivity', [123]]] as [$action, $args]) {
            [$status, $location] = $this->visit($action, $args);

            $this->assertContains($status, [301, 302, 303, 307], "A página {$action} aberta em outro subsite redireciona");
            $this->assertStringStartsWith($mestres->getSubsiteUrl(), $location, "A página {$action} vai para o subsite dos Mestres");
        }

        $this->assertStringEndsWith('/123/', $location, 'O festejo em edição segue na mesma página');
    }

    function testTheRegistrationRouteGoesToTheMestresSiteBeforeTheLogin()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $mestres = $this->moveToAnotherSite($fixed);
        $this->logout();

        [$status, $location] = $this->visit('index');

        $this->assertContains($status, [301, 302, 303, 307], 'O /mdc/ aberto em outro subsite redireciona');
        $this->assertStringStartsWith($mestres->getSubsiteUrl(), $location, 'Para o subsite dos Mestres, e não para o login daqui');
        $this->assertEmpty($this->app->repo('Registration')->findBy(['opportunity' => $fixed->id]), 'Nada é gravado fora do subsite');
    }

    function testWithoutTheProgramTheirPagesAreNotRedirected()
    {
        $this->app->config[FixedOpportunity::CONFIG_KEY] = null;
        $this->login($this->userDirector->createUser());

        [$status, $location] = $this->visit('masters');

        $this->assertEquals(200, $status, 'Sem a oportunidade do programa, a página abre onde foi pedida');
        $this->assertSame('', $location);
    }
}
