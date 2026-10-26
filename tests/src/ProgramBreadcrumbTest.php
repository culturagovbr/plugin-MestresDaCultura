<?php

namespace Tests\MDC;

use MDC\Services\ProgramBreadcrumb;
use Tests\Abstract\TestCase;

// A trilha das telas do cadastro e da moderação do programa só leva a páginas que abrem.
class ProgramBreadcrumbTest extends TestCase
{
    function testTheRegistrationPathGoesThroughThePanel()
    {
        $single = ProgramBreadcrumb::registration('http://x/inscricao/7/', false);
        $this->assertSame(['Início', 'Painel', 'Cadastro'], array_column($single, 'label'));
        $this->assertSame([$this->app->createUrl('site', 'index'), $this->app->createUrl('panel', 'index')], array_column(array_slice($single, 0, 2), 'url'));
        $this->assertArrayNotHasKey('url', $single[2], 'Na ficha, o cadastro é a página atual');

        $form = ProgramBreadcrumb::registration('http://x/inscricao/7/', true);
        $this->assertSame('http://x/inscricao/7/', $form[2]['url'] ?? null, 'No formulário, o cadastro leva à ficha');
    }

    function testTheEvaluationPathOpensForTheModerator()
    {
        $core = [
            ['label' => 'Início', 'url' => $this->app->createUrl('panel', 'opportunities')],
            ['label' => 'Painel de controle', 'url' => $this->app->createUrl('panel', 'opportunities')],
            ['label' => 'Minhas Avaliações', 'url' => $this->app->createUrl('panel', 'evaluations')],
            ['label' => 'Lista de Avaliações', 'url' => 'http://x/avaliacoes/9/'],
            ['label' => 'Formulário de avaliação'],
        ];

        $path = ProgramBreadcrumb::evaluation($core);

        $this->assertSame(['Início', 'Painel de controle', 'Moderação de cadastros', 'Formulário de avaliação'], array_column($path, 'label'));
        $this->assertSame([$this->app->createUrl('site', 'index'), $this->app->createUrl('panel', 'index'), 'http://x/avaliacoes/9/'], array_column(array_slice($path, 0, 3), 'url'));
        $this->assertNotContains($this->app->createUrl('panel', 'opportunities'), array_column($path, 'url'), 'Nada leva a "Minhas oportunidades"');
    }
}
