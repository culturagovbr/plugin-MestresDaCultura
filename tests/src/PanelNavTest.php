<?php

namespace Tests\MDC;

use MapasCulturais\App;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class PanelNavTest extends TestCase
{
    use UserDirector;

    private function coreNav(App $app): array
    {
        return [
            'panel-menu' => [
                'label' => 'Menu do Painel de Controle',
                'column' => 'left',
                'items' => [['route' => 'panel/index', 'label' => 'Painel de Controle']],
            ],
            'opportunities' => [
                'label' => 'Editais e oportunidades',
                'column' => 'left',
                'items' => [
                    ['route' => 'panel/opportunities', 'label' => 'Minhas oportunidades'],
                    ['route' => 'panel/registrations', 'label' => 'Minhas inscrições'],
                    ['route' => 'panel/evaluations', 'label' => 'Minhas avaliações'],
                ],
                'condition' => fn() => $app->isEnabled('opportunities'),
            ],
            'main' => [
                'label' => 'Gerenciamento de entidades',
                'column' => 'left',
                'items' => [
                    ['route' => 'panel/agents', 'label' => 'Meus Agentes', 'condition' => fn() => $app->isEnabled('agents')],
                    ['route' => 'panel/spaces', 'label' => 'Meus Espaços', 'condition' => fn() => $app->isEnabled('spaces')],
                ],
            ],
            'admin' => [
                'label' => 'Administração',
                'column' => 'right',
                'items' => [['route' => 'panel/seals', 'label' => 'Gestão de Selos']],
            ],
            'user' => [
                'label' => 'Usuário Logado',
                'column' => 'user',
                'items' => [['route' => 'auth/logout', 'label' => 'Sair']],
            ],
        ];
    }

    private function applyNav(): array
    {
        $nav = $this->coreNav($this->app);
        $this->app->applyHook('panel.nav', [&$nav]);

        return $nav;
    }

    private function labels(array $nav): array
    {
        $passes = fn(array $entry) => !isset($entry['condition']) || ($entry['condition'])();

        $labels = [];
        foreach ($nav as $group) {
            if (!$passes($group)) {
                continue;
            }

            foreach ($group['items'] ?? [] as $item) {
                if ($passes($item)) {
                    $labels[] = $item['label'];
                }
            }
        }

        return $labels;
    }

    function testSaasSuperAdminKeepsTheWholeCoreMenu()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $nav = $this->applyNav();
        $labels = $this->labels($nav);

        foreach (['Painel de Controle', 'Minhas oportunidades', 'Minhas inscrições', 'Meus Agentes', 'Meus Espaços', 'Gestão de Selos'] as $expected) {
            $this->assertContains($expected, $labels, "saasSuperAdmin não pode perder \"{$expected}\" do menu");
        }

        $this->assertArrayHasKey('mdc-criar', $nav, 'O grupo do programa deve ser acrescentado ao menu do core');
    }

    function testSaasSuperAdminSeesEntityItemsEvenWithEntityDisabled()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertFalse($this->app->isEnabled('agents'), 'A entidade precisa estar desabilitada, senão este teste não prova nada');
        $this->assertContains('Meus Agentes', $this->labels($this->applyNav()), 'A condição de isEnabled não pode alcançar o saasSuperAdmin');
    }

    function testRegularUserSeesOnlyTheProgramMenu()
    {
        $this->login($this->userDirector->createUser());

        $nav = $this->applyNav();
        $labels = $this->labels($nav);

        foreach (['Painel de Controle', 'Minhas oportunidades', 'Meus Agentes', 'Meus Espaços', 'Minhas inscrições'] as $removed) {
            $this->assertNotContains($removed, $labels, "\"{$removed}\" não deve aparecer para usuário comum");
        }

        $this->assertContains('Criar Cadastro de Festejo Popular', $labels);
        $this->assertContains('Conta e Privacidade', $labels);
        $this->assertContains('Sair', $labels);
    }

    function testAdminKeepsTheReducedMenu()
    {
        $this->login($this->userDirector->createUser('admin'));

        $labels = $this->labels($this->applyNav());

        $this->assertNotContains('Meus Agentes', $labels, 'Admin mantém o menu enxuto: só saasSuperAdmin é preservado');
        $this->assertContains('Gestão de Selos', $labels, 'O grupo de administração é preservado');
        $this->assertContains('Minhas oportunidades', $labels, 'Minhas oportunidades passa para o grupo de administração');
    }

    function testOnlyAdminsSeeTheTreeCuration()
    {
        $this->login($this->userDirector->createUser('admin'));
        $this->assertContains('Curadoria da Árvore de Atuação', $this->labels($this->applyNav()));

        $this->login($this->userDirector->createUser());
        $this->assertNotContains('Curadoria da Árvore de Atuação', $this->labels($this->applyNav()));
    }
}
