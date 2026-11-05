<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Controller;
use MapasCulturais\Entities\Subsite;

/**
 * A URL da mesma página (controlador, ação e parâmetros) em outro subsite da instalação, com o subsite atual e os
 * atalhos de rota do destino trocados só durante a montagem.
 */
class SubsiteRedirect
{
    // Atalhos de rota do subsite dos Mestres, à frente dos do core.
    const PROGRAM_SHORTCUTS = [
        'festejo' => ['event', 'single'],
        'meus-festejos' => ['panel', 'events'],
        'certificado' => ['seal', 'sealRelation'],
        'perfil' => ['agent', 'single'],
        'editar-perfil' => ['agent', 'edit'],
    ];

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
        $shortcuts = $app->config['routes']['shortcuts'];
        $app->subsite = $subsite;

        $app->config['routes']['shortcuts'] = $subsite && $subsite->id === FixedOpportunity::configuredSubsiteId()
            ? self::PROGRAM_SHORTCUTS + $shortcuts
            : array_diff_key($shortcuts, self::PROGRAM_SHORTCUTS);

        try {
            return $app->createUrl($controller->id, $controller->action, $data);
        } finally {
            $app->subsite = $current;
            $app->config['routes']['shortcuts'] = $shortcuts;
        }
    }

    static function isCurrent(?Subsite $subsite): bool
    {
        return $subsite?->id === App::i()->subsite?->id;
    }
}
