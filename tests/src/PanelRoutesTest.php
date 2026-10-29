<?php

namespace Tests\MDC;

use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// No subsite dos Mestres, o painel de quem não é admin tem só as telas do programa; as do Mapa genérico não abrem por URL.
class PanelRoutesTest extends TestCase
{
    use OpportunityBuilder;
    use RequestFactory;
    use UserDirector;

    // "Meus eventos" é a lista dos festejos de quem cadastra.
    private const OPEN = ['index', 'my-account', 'registrations', 'evaluations', 'events'];

    private const CLOSED = ['apps', 'my-app', 'validations', 'accountability'];

    // Configura a oportunidade do programa, que o painel do tema procura.
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        $fixed = $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();

        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
    }

    private function statusOf(User $user, string $action): int
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('panel', $action), false);

        return $this->app->response->getStatusCode();
    }

    function testTheProgramScreensOpenForTheMaster()
    {
        $master = $this->userDirector->createUser();

        foreach (self::OPEN as $action) {
            $this->assertSame(200, $this->statusOf($master, $action), "panel/{$action} segue aberto");
        }
    }

    function testTheOtherPanelScreensAreClosedForTheMaster()
    {
        $master = $this->userDirector->createUser();

        foreach (self::CLOSED as $action) {
            $this->assertEquals(404, $this->statusOf($master, $action), "panel/{$action} não abre para quem não é admin");
        }
    }

    // Aplicativos e validações não abrem no subsite, nem para o gestor nem para o saasSuperAdmin.
    function testUnusedScreensAreClosedForEveryone()
    {
        foreach (['admin', 'saasSuperAdmin'] as $role) {
            $user = $this->userDirector->createUser($role);

            foreach (['apps', 'my-app', 'validations', 'accountability'] as $action) {
                $this->assertEquals(404, $this->statusOf($user, $action), "panel/{$action} não abre para {$role}");
            }

            $this->login($user);
            $this->assertStatus404($this->requestFactory->POST('app', 'index', [], ['name' => 'Teste']), "A criação de aplicativo recusa {$role}");
        }
    }

    function testTheManagerKeepsTheAdministrationScreens()
    {
        $admin = $this->userDirector->createUser('admin');

        foreach (['seals', 'user-management'] as $action) {
            $this->assertSame(200, $this->statusOf($admin, $action), "panel/{$action} segue aberto para o gestor");
        }
    }

    // "Minhas oportunidades" só abre para o saasSuperAdmin.
    function testMyOpportunitiesOpensOnlyForTheSaasSuperAdmin()
    {
        $this->assertEquals(404, $this->statusOf($this->userDirector->createUser('admin'), 'opportunities'), 'O gestor não abre');
        $this->assertEquals(404, $this->statusOf($this->userDirector->createUser(), 'opportunities'), 'O mestre não abre');
        $this->assertSame(200, $this->statusOf($this->userDirector->createUser('saasSuperAdmin'), 'opportunities'), 'O saasSuperAdmin abre');
    }

    // Toda tela do painel no menu da mestra está em Hooks\PanelMenu::OPEN_SCREENS e abre.
    function testEveryPanelScreenInTheMastersMenuOpens()
    {
        $master = $this->userDirector->createUser();
        $this->login($master);
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('panel', 'index'), false);

        $routes = [];
        foreach ($this->app->view->jsObject['config']['panelNav'] ?? [] as $group) {
            foreach ($group['items'] as $item) {
                if (str_starts_with($item['route'] ?? '', 'panel/')) {
                    $routes[] = substr($item['route'], strlen('panel/'));
                }
            }
        }

        $this->assertNotEmpty($routes, 'O menu da mestra foi montado');

        foreach (array_unique($routes) as $action) {
            $this->assertSame(200, $this->statusOf($master, $action), "panel/{$action} está no menu da mestra e precisa abrir");
        }
    }
}
