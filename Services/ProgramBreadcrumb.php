<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;

/**
 * O caminho (breadcrumb) das telas do cadastro e da moderação do programa: a mestra passa pelo painel; o moderador,
 * pela lista da moderação dele.
 */
class ProgramBreadcrumb
{
    /** Ficha ou formulário do cadastro: Início › Painel de controle › Cadastro (no formulário, o cadastro leva à ficha). */
    static function registration(string $singleUrl, bool $isForm): array
    {
        $app = App::i();
        $registration = ['label' => i::__('Cadastro')];

        if ($isForm) {
            $registration['url'] = $singleUrl;
        }

        return [
            ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
            ['label' => i::__('Painel de controle'), 'url' => $app->createUrl('panel', 'index')],
            $registration,
        ];
    }

    /**
     * Tela de avaliação: Início › Painel de controle › Moderação de cadastros (a "Lista de Avaliações" do core) ›
     * avaliação.
     */
    static function evaluation(array $core): array
    {
        $app = App::i();
        $list = $core[3]['url'] ?? $app->createUrl('panel', 'evaluations');

        return [
            ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
            ['label' => i::__('Painel de controle'), 'url' => $app->createUrl('panel', 'index')],
            ['label' => i::__('Moderação de cadastros'), 'url' => $list],
            end($core) ?: ['label' => i::__('Formulário de avaliação')],
        ];
    }

    /**
     * O próprio perfil da mestra e do moderador: Início › Painel de controle › Meu perfil; na edição, o perfil leva à
     * página dele e vem "Editar perfil".
     */
    static function profile(string $singleUrl, bool $isEdit): array
    {
        $app = App::i();
        $path = [
            ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
            ['label' => i::__('Painel de controle'), 'url' => $app->createUrl('panel', 'index')],
            ['label' => i::__('Meu perfil')],
        ];

        if ($isEdit) {
            $path[2]['url'] = $singleUrl;
            $path[] = ['label' => i::__('Editar perfil')];
        }

        return $path;
    }
}
