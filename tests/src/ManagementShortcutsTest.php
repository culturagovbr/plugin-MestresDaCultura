<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Quem controla a oportunidade do programa tem no menu do painel o grupo "Gestão do cadastro", com atalhos para os
// cadastros, as avaliações e a comissão; os outros não.
class ManagementShortcutsTest extends TestCase
{
    use OpportunityBuilder;
    use ProgramOpportunity;
    use RequestFactory;
    use UserDirector;

    private Opportunity $fixed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixed = $this->createProgramOpportunity();
    }

    private function navFor(User $user): array
    {
        $this->login($user);
        $nav = [];
        $this->app->applyHook('panel.nav', [&$nav]);

        return $nav;
    }

    /** @return array{int, string} status e Location */
    private function open(User $user, string $controller, string $action, array $params = []): array
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run($this->requestFactory->GET($controller, $action, $params), false);

        return [$this->app->response->getStatusCode(), $this->app->response->getHeaderLine('Location')];
    }

    function testWhoControlsTheProgramSeesTheManagementShortcuts()
    {
        $group = $this->navFor($this->userDirector->createUser('admin'))['mdc-gestao'] ?? [];

        $this->assertSame('Gestão do cadastro', $group['label'] ?? null);
        $this->assertSame(
            [['opportunity/registrations', [$this->fixed->id]], ['opportunity/allEvaluations', [$this->fixed->id]], ['panel/mdc-committee', []]],
            array_map(fn ($item) => [$item['route'], $item['params'] ?? []], $group['items'] ?? []),
            'Cadastros, Avaliações e Comissão, nesta ordem'
        );
    }

    function testWhoDoesNotControlTheProgramDoesNotSeeThem()
    {
        $this->assertArrayNotHasKey('mdc-gestao', $this->navFor($this->userDirector->createUser()));
    }

    function testTheCommitteeShortcutOpensThePhasesTab()
    {
        $manager = $this->userDirector->createUser('admin');

        [$status, $location] = $this->open($manager, 'panel', 'mdc-committee');
        $this->assertContains($status, [301, 302, 303, 307], 'O atalho da comissão redireciona');
        $this->assertSame($this->app->createUrl('opportunity', 'edit', [$this->fixed->id]) . '#config', $location, 'Para a aba de fases, onde fica a comissão');

        [$status] = $this->open($this->userDirector->createUser(), 'panel', 'mdc-committee');
        $this->assertNotContains($status, [301, 302, 303, 307], 'Quem não controla não é levado à gestão');
    }
}
