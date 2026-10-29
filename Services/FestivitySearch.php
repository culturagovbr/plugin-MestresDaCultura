<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;

/**
 * A busca de festejos (Controller::GET_festivities, mdc-festivities-search): a API de eventos do core só com o selo
 * dos festejos, filtrando pelos campos do festejo. O mês da agenda vira a lista dos festejos com algum período naquele
 * mês (Hooks\Festivities::openTheFestivitySearch).
 */
class FestivitySearch
{
    // O parâmetro da API de eventos para o mês (1 a 12).
    const MONTH_PARAM = '@mdcMes';

    /**
     * Só os festejos, com o selo; sem o selo configurado, nenhum.
     *
     * @return list<int> os festejos publicados com algum período que começa ou termina no mês
     */
    static function idsInMonth(int $month): array
    {
        $seal = (new FestivitySeal)->configuredId();

        if (!$seal) {
            return [];
        }

        return array_map('intval', App::i()->conn->fetchFirstColumn(
            'SELECT DISTINCT o.event_id FROM event_occurrence o
               JOIN event e ON e.id = o.event_id
               JOIN seal_relation r ON r.object_id = e.id AND r.object_type = :type AND r.seal_id = :seal
             WHERE e.status = :enabled AND o.status > 0
               AND (EXTRACT(MONTH FROM o.starts_on) = :month OR EXTRACT(MONTH FROM o.until) = :month)',
            ['type' => Event::class, 'seal' => $seal, 'enabled' => Event::STATUS_ENABLED, 'month' => $month]
        ));
    }
}
