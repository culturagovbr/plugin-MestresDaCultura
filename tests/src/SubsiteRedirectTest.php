<?php

namespace Tests\MDC;

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

// No site dos Mestres, a inscrição de outro edital abre no site daquele edital.
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

    /** Põe a oportunidade num outro site da instalação. */
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
    private function open(User $user, Registration $registration, string $action = 'single'): array
    {
        $this->login($this->app->repo('User')->find($user->id));
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('registration', $action, [$registration->id]), false);

        return [$this->app->response->getStatusCode(), $this->app->response->getHeaderLine('Location')];
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
            $this->assertStringStartsWith($subsite->getSubsiteUrl(), $location, "A {$action} vai para o site do edital");
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

        $this->assertEquals(404, $status, 'Sem outro site para onde ir, não abre aqui');
    }

    function testTheProgramRegistrationOpensNormally()
    {
        $fixed = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $user = $this->userDirector->createUser();
        $own = $this->registrationOf($user, $fixed);

        [$status, $location] = $this->open($user, $own);

        // O rascunho pode redirecionar, mas só dentro do próprio subsite.
        $this->assertNotEquals(404, $status);
        $this->assertTrue($location === '' || str_starts_with($location, $this->app->baseUrl), 'O cadastro do programa fica no site dos Mestres');
    }
}
