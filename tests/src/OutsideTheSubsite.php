<?php

namespace Tests\MDC;

/**
 * Roda `$run` com a view do BaseV2, como fora do subsite dos Mestres. A view não renderiza tela; serve para os ganchos
 * que perguntam Plugin::isTheme().
 */
trait OutsideTheSubsite
{
    private function outsideTheSubsite(callable $run): mixed
    {
        $theme = $this->app->view;
        $this->app->view = (new \ReflectionClass(\MapasCulturais\Themes\BaseV2\Theme::class))->newInstanceWithoutConstructor();

        try {
            return $run();
        } finally {
            $this->app->view = $theme;
        }
    }
}
