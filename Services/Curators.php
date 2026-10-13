<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use MDC\Entities\Node;

/**
 * Quem faz a curadoria da Árvore de Atuação: hoje, o administrador do subsite. A página, as rotas, a
 * entidade e os serviços perguntam aqui, para um papel próprio de curador mudar num lugar só.
 */
class Curators
{
    static function includes(?User $user = null): bool
    {
        return ($user ?? App::i()->user)->is('admin');
    }

    // Exige a permissão de curador nas tabelas da curadoria que não são entidade.
    static function require(?Node $node = null): void
    {
        if (!self::includes()) {
            throw new PermissionDenied(App::i()->user, $node, 'modify');
        }
    }

    static function refusal(): string
    {
        return i::__('Só administradores fazem a curadoria da árvore.');
    }
}
