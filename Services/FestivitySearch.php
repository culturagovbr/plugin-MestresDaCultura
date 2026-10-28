<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;

/**
 * A busca de festejos (Controller::GET_festivities, mdc-festivities-search), como a de Mestras e Mestres: a API de
 * eventos do core só com quem tem o selo dos festejos, filtrando pelos campos do festejo. O mês da agenda não é um
 * campo: vira a lista dos festejos com algum período naquele mês (Plugin::openTheFestivitySearch).
 */
class FestivitySearch
{
    // O parâmetro da API de eventos para o mês (1 a 12).
    const MONTH_PARAM = '@mdcMes';

    /** @return list<int> os festejos publicados com algum período que começa ou termina no mês */
    static function idsInMonth(int $month): array
    {
        return array_map('intval', App::i()->conn->fetchFirstColumn(
            'SELECT DISTINCT o.event_id FROM event_occurrence o JOIN event e ON e.id = o.event_id
             WHERE e.status = :enabled AND o.status > 0
               AND (EXTRACT(MONTH FROM o.starts_on) = :month OR EXTRACT(MONTH FROM o.until) = :month)',
            ['enabled' => Event::STATUS_ENABLED, 'month' => $month]
        ));
    }
}
