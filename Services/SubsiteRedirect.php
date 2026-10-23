<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Controller;
use MapasCulturais\Entities\Subsite;

/**
 * A URL da mesma página (controlador, ação e parâmetros) em outro subsite da instalação, com o subsite atual trocado
 * só durante a montagem.
 */
class SubsiteRedirect
{
    /** @param ?Subsite $subsite null é o Mapa principal */
    static function urlOn(?Subsite $subsite, Controller $controller): string
    {
        $app = App::i();
        $data = $controller->data;

        if (isset($data['id'])) {
            $data[0] = $data['id'];
            unset($data['id']);
        }

        $current = $app->subsite;
        $app->subsite = $subsite;

        try {
            return $app->createUrl($controller->id, $controller->action, $data);
        } finally {
            $app->subsite = $current;
        }
    }

    static function isCurrent(?Subsite $subsite): bool
    {
        return $subsite?->id === App::i()->subsite?->id;
    }
}
