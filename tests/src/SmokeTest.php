<?php

namespace Tests\MDC;

use MapasCulturais\Themes\BaseV2\Theme as BaseV2Theme;
use Tests\Abstract\TestCase;

class SmokeTest extends TestCase
{
    function testPluginIsRegistered()
    {
        $this->assertArrayHasKey('MDC', $this->app->plugins);
    }

    function testPluginIsInstanceOfPluginClass()
    {
        $this->assertInstanceOf(\MDC\Plugin::class, $this->app->plugins['MDC']);
    }

    function testActiveThemeIsTheProgramTheme()
    {
        $this->assertSame('MestresDaCultura', $this->app->config['themes.active']);
    }

    function testThemeInheritsFromBaseV2()
    {
        $this->assertInstanceOf(BaseV2Theme::class, $this->app->view);
    }
}
