<?php

namespace Tests\MDC;

use Tests\Abstract\TestCase;

// No subsite dos Mestres, a página do evento é a do festejo: endereço `/festejo/ID/` e botão "Editar festejo".
class FestivityAddressTest extends TestCase
{
    function testTheEventPageIsAtFestejo()
    {
        $this->assertStringEndsWith('/festejo/91/', $this->app->createUrl('event', 'single', [91]));
        $this->assertSame(['event', 'single'], $this->app->config['routes']['shortcuts']['evento'] ?? null, 'O endereço antigo segue abrindo');
    }

    function testTheOwnerButtonSaysFestejo()
    {
        $texts = ['Evento' => 'evento', 'Agente' => 'agente'];
        $this->app->applyHook('component(event.single.entity-actions).texts', [&$texts]);

        $this->assertSame(['Evento' => 'festejo', 'Agente' => 'agente'], $texts);
    }
}
