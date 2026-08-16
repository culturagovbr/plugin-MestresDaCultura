<?php

namespace Tests\MDC;

use MapasCulturais\Themes\BaseV2\Theme as BaseV2Theme;
use Tests\Abstract\TestCase;

/**
 * Valida o add-on de testes do plugin: configuração, autoload e tema, conforme
 * o docker-compose.yml deste diretório.
 *
 * Sem o override, a suíte do core roda sem o plugin e sem o tema — estes testes
 * só passam com `-f ../src/plugins/MDC/tests/docker-compose.yml`.
 */
class SmokeTest extends TestCase
{
    function testPluginEstaRegistrado()
    {
        $this->assertArrayHasKey('MDC', $this->app->plugins);
    }

    function testPluginEhDaClasseDoPlugin()
    {
        $this->assertInstanceOf(\MDC\Plugin::class, $this->app->plugins['MDC']);
    }

    function testTemaAtivoEhODoPrograma()
    {
        $this->assertSame('MestresDaCultura', $this->app->config['themes.active']);
    }

    function testTemaHerdaDoBaseV2()
    {
        $this->assertInstanceOf(BaseV2Theme::class, $this->app->view);
    }
}
