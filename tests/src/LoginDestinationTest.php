<?php

namespace Tests\MDC;

use Tests\Abstract\TestCase;

// O destino do cadastro dos Mestres guardado antes do login vale para um login só.
class LoginDestinationTest extends TestCase
{
    private const KEY = 'mapasculturais.auth.redirect_path';

    private function read(string $stored): string
    {
        $_SESSION[self::KEY] = $stored;
        $redirect = $stored;
        $this->app->applyHookBoundTo($this->app->auth, 'auth.redirectUrl', [&$redirect]);

        return $redirect;
    }

    protected function tearDown(): void
    {
        unset($_SESSION[self::KEY]);
        parent::tearDown();
    }

    function testTheProgramDestinationLeadsThisLoginAndIsThenForgotten()
    {
        $path = parse_url($this->app->createUrl('mdc', 'index'), PHP_URL_PATH);

        foreach ([$path, rtrim($path, '/'), "{$path}index/", "http://localhost{$path}?x=1"] as $stored) {
            $this->read($stored);
            $this->assertArrayNotHasKey(self::KEY, $_SESSION, "O próximo login não herda o destino {$stored}");
        }
    }

    function testOtherDestinationsStayAsTheCoreKeepsThem()
    {
        $this->assertSame('/painel/minhas-oportunidades/', $this->read('/painel/minhas-oportunidades/'));
        $this->assertSame('/painel/minhas-oportunidades/', $_SESSION[self::KEY] ?? null);
    }
}
