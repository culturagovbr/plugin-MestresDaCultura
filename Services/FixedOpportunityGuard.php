<?php

namespace MDC\Services;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\UserInterface;

/**
 * Só o saasSuperAdmin altera a estrutura da oportunidade do programa (ela, as fases e o formulário), e só ele a
 * arquiva, exclui ou duplica. A recusa é no servidor.
 */
class FixedOpportunityGuard
{
    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    function locks(?Opportunity $opportunity, ?UserInterface $user): bool
    {
        return $user && !$user->is('saasSuperAdmin') && $this->fixedOpportunity->matches($opportunity);
    }
}
