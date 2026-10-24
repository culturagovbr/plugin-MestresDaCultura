<?php

namespace MDC\Services;

/**
 * No subsite, a API de inscrições só alcança a oportunidade do programa e as fases dela; admin e subconsultas seguem
 * como no core.
 */
class RegistrationScope
{
    /**
     * O que a consulta pediu ("EQ(1)", "IN(1,2)"), cortado ao que é do programa. Outro operador, ou nada pedido,
     * vira o programa inteiro; pedido só de fora vira o id 0, que não devolve nada.
     *
     * @param int[] $allowed
     * @return int[]
     */
    static function narrow(mixed $requested, array $allowed): array
    {
        if (is_string($requested) && preg_match('/^\s*(EQ|IN)\(([\d,\s]*)\)\s*$/i', $requested, $match)) {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $match[2])), 'strlen'));

            return array_values(array_intersect($allowed, $ids)) ?: [0];
        }

        return $allowed;
    }
}
