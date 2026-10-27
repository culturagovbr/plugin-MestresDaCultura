<?php

namespace Tests\MDC;

use MapasCulturais\App;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

class PanelNavTest extends TestCase
{
    use OpportunityBuilder;
    use ProgramOpportunity;
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
                    ['route' => 'panel/validations', 'label' => 'Minhas validações'],
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
            'more' => [
                'label' => 'Outras opções',
                'column' => 'right',
                'items' => [['route' => 'panel/my-account', 'label' => 'Conta e Privacidade'], ['route' => 'panel/apps', 'label' => 'Meus aplicativos']],
            ],
            'admin' => [
                'label' => 'Administração',
                'column' => 'right',
                'items' => [['route' => 'panel/seals', 'label' => 'Gestão de Selos']],
            ],
            'user' => [
                'label' => 'Usuário Logado',
                'column' => 'user',
                'items' => [['route' => 'agent/single', 'label' => 'Meu Perfil'], ['route' => 'auth/logout', 'label' => 'Sair']],
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

        foreach (['Painel de Controle', 'Minhas oportunidades', 'Minhas inscrições', 'Meus Agentes', 'Gestão de Selos'] as $expected) {
            $this->assertContains($expected, $labels, "saasSuperAdmin não pode perder \"{$expected}\" do menu");
        }

        foreach (['Meus Espaços', 'Meus aplicativos', 'Minhas validações'] as $unused) {
            $this->assertNotContains($unused, $labels, "\"{$unused}\" leva a uma tela que o subsite não usa");
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

        foreach (['Minhas oportunidades', 'Meus Agentes', 'Meus Espaços', 'Minhas inscrições'] as $removed) {
            $this->assertNotContains($removed, $labels, "\"{$removed}\" não deve aparecer para usuário comum");
        }

        // Os grupos do core como eles são, menos o que o subsite não usa: "Menu do Painel de Controle" (o caminho de
        // volta a "Meus cadastros", e a lista dos festejos), "Outras opções" e o do usuário logado (Meu Perfil, Sair).
        $this->assertSame(
            [['route' => 'panel/index', 'label' => 'Painel de Controle'], ['route' => 'panel/events', 'icon' => 'celebration', 'label' => 'Meus festejos']],
            $nav['panel-menu']['items'] ?? null,
            'Menu do Painel de Controle'
        );
        $this->assertSame('Outras opções', $nav['more']['label'] ?? null);
        $this->assertSame(['Conta e Privacidade'], array_column($nav['more']['items'] ?? [], 'label'), 'Sem "Meus aplicativos", que o subsite não usa');
        $this->assertSame(['Meu Perfil', 'Sair'], array_column($nav['user']['items'] ?? [], 'label'), 'O grupo do usuário logado, como no core');

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
        $this->assertNotContains('Minhas oportunidades', $labels, 'O gestor chega ao programa pela Gestão do cadastro');
    }

    // A trilha do gestor não leva a "Minhas oportunidades".
    function testTheManagerBreadcrumbSkipsMyOpportunities()
    {
        $opportunity = $this->createProgramOpportunity();
        $crumbs = function () use ($opportunity) {
            $this->app->view->breadcrumb = [];
            $this->app->view->addOpportunityBreadcramb('Lista de inscrições', $opportunity);

            return array_column($this->app->view->breadcrumb, 'label');
        };

        $this->login($this->userDirector->createUser('admin'));
        $this->assertNotContains('Minhas oportunidades', $crumbs());
        $this->assertContains('Lista de inscrições', $crumbs());

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->assertContains('Minhas oportunidades', $crumbs(), 'O saasSuperAdmin segue como no core');
    }

    // Na trilha do moderador, "Moderação de cadastros" no lugar de "Minhas avaliações".
    function testTheModeratorBreadcrumbSaysModeration()
    {
        $opportunity = $this->createProgramOpportunity();
        $committee = current(array_filter(array_map(fn ($phase) => $phase->evaluationMethodConfiguration, $opportunity->allPhases)));
        $moderator = $this->userDirector->createUser();
        $committee->createAgentRelation($this->app->repo('Agent')->find($moderator->profile->id), 'Moderação', true);

        $this->login($moderator);
        $this->app->view->breadcrumb = [];
        $this->app->view->addOpportunityBreadcramb('Lista de avaliações', $this->app->repo('EvaluationMethodConfiguration')->find($committee->id));

        $labels = array_column($this->app->view->breadcrumb, 'label');
        $this->assertContains('Moderação de cadastros', $labels);
        $this->assertNotContains('Minhas avaliações', $labels);
    }

    function testOnlyAdminsSeeTheTreeCuration()
    {
        $this->login($this->userDirector->createUser('admin'));
        $this->assertContains('Curadoria da Árvore de Atuação', $this->labels($this->applyNav()));

        $this->login($this->userDirector->createUser());
        $this->assertNotContains('Curadoria da Árvore de Atuação', $this->labels($this->applyNav()));
    }

    /** @return array<string, array> os itens do menu de quem está logado, pelo rótulo */
    private function itemsOf(User $user): array
    {
        $this->login($user);
        $items = [];
        foreach ($this->applyNav() as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (!isset($item['condition']) || ($item['condition'])()) {
                    $items[$item['label']] = $item;
                }
            }
        }

        return $items;
    }

    // O moderador do programa vai direto à lista da moderação.
    function testTheModeratorGoesStraightToTheModeration()
    {
        $fixed = $this->createProgramOpportunity();
        $moderator = $this->userDirector->createUser();
        $fixed->evaluationMethodConfiguration->createAgentRelation($moderator->profile, 'Moderação', true);

        $items = $this->itemsOf($moderator);

        $this->assertSame(['opportunity/userEvaluations', [$fixed->id]], [$items['Moderação de cadastros']['route'] ?? null, $items['Moderação de cadastros']['params'] ?? null], 'O item leva à lista da moderação');
        $this->assertArrayNotHasKey('Minhas avaliações', $items, 'No lugar de "Minhas avaliações"');
    }

    function testWhoIsNotAnActiveModeratorDoesNotSeeTheItem()
    {
        $fixed = $this->createProgramOpportunity();
        $disabled = $this->userDirector->createUser();
        $fixed->evaluationMethodConfiguration->createAgentRelation($disabled->profile, 'Moderação', true)->disable();

        $other = $this->opportunityBuilder->reset(owner: $fixed->owner, owner_entity: $fixed->owner)->fillRequiredProperties()->save()
            ->addEvaluationPhase(\Tests\Enums\EvaluationMethods::simple)->save()->done()->getInstance();
        $evaluator = $this->userDirector->createUser();
        $other->evaluationMethodConfiguration->createAgentRelation($evaluator->profile, 'Comissão', true);

        foreach (['desabilitado' => $disabled, 'de outro edital' => $evaluator] as $who => $user) {
            $labels = array_keys($this->itemsOf($user));
            $this->assertNotContains('Moderação de cadastros', $labels, "O avaliador {$who} não vê a moderação");
            $this->assertNotContains('Minhas avaliações', $labels, "Nem as avaliações de outros editais");
        }
    }
}
