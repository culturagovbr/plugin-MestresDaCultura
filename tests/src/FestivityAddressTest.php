<?php

namespace Tests\MDC;

use MDC\Services\PageTitles;
use Tests\Abstract\TestCase;

// No subsite dos Mestres, os endereços do programa (festejo, meus festejos, edição, certificado e perfil), os títulos
// das abas e o botão "Editar festejo".
class FestivityAddressTest extends TestCase
{
    function testTheEventPageIsAtFestejo()
    {
        $this->assertStringEndsWith('/festejo/91/', $this->app->createUrl('event', 'single', [91]));
        $this->assertSame(['event', 'single'], $this->app->config['routes']['shortcuts']['evento'] ?? null, 'O endereço antigo segue abrindo');
    }

    function testMyFestivitiesAndTheEditionHaveTheirOwnAddresses()
    {
        $this->assertStringEndsWith('/meus-festejos/', $this->app->createUrl('panel', 'events'));
        $this->assertSame(['panel', 'events'], $this->app->config['routes']['shortcuts']['meus-eventos'] ?? null, 'O endereço antigo segue abrindo');
        $this->assertStringEndsWith('/editar-festejo/92/', $this->app->createUrl('mdc', 'editFestivity', [92]));
        $this->assertStringEndsWith('/cadastrar-festejo/', $this->app->createUrl('mdc', 'festivity'));
    }

    function testTheCertificateAndTheProfileHaveTheirOwnAddresses()
    {
        $this->assertStringEndsWith('/certificado/390/', $this->app->createUrl('seal', 'sealRelation', [390]));
        $this->assertStringEndsWith('/perfil/7/', $this->app->createUrl('agent', 'single', [7]));
        $this->assertStringEndsWith('/editar-perfil/7/', $this->app->createUrl('agent', 'edit', [7]));
        $this->assertSame(['agent', 'single'], $this->app->config['routes']['shortcuts']['agente'] ?? null, 'O endereço antigo segue abrindo');
    }

    function testTheScreensWithoutTitleGetOne()
    {
        $site = $this->app->siteName;

        $this->assertSame("Meus festejos - {$site}", PageTitles::of('panel', 'events'));
        $this->assertSame("Certificado - {$site}", PageTitles::of('seal', 'sealRelation'));
        $this->assertSame("Política de Privacidade - {$site}", PageTitles::of('lgpd', 'view', ['privacyPolicy']));
        $this->assertNull(PageTitles::of('event', 'single'), 'A página com nome segue com o título do core');
    }

    function testTheOwnerButtonSaysFestejo()
    {
        $texts = ['Evento' => 'evento', 'Agente' => 'agente'];
        $this->app->applyHook('component(event.single.entity-actions).texts', [&$texts]);

        $this->assertSame(['Evento' => 'festejo', 'Agente' => 'agente'], $texts);
    }
}
