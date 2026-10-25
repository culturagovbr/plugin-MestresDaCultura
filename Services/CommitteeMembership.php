<?php

namespace MDC\Services;

use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\i;

/**
 * A mesma pessoa não entra duas vezes na mesma comissão do programa, habilitada, desabilitada ou convidada.
 */
class CommitteeMembership
{
    static function message(): string
    {
        return i::__('Esta pessoa já faz parte desta comissão, habilitada, desabilitada ou com o convite pendente.');
    }

    // Conta as relações habilitadas (1), desabilitadas (8) e com o convite pendente (-5).
    static function alreadyIn(?EvaluationMethodConfiguration $committee, ?string $group, ?int $agentId): bool
    {
        if (!$committee?->id || !$group || !$agentId) {
            return false;
        }

        foreach ($committee->getAgentRelations(null, true) as $relation) {
            if ($relation->group === $group && $relation->agent->id === $agentId) {
                return true;
            }
        }

        return false;
    }
}
