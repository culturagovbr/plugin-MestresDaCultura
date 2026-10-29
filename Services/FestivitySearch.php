<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Registration;

/**
 * A busca de festejos (Controller::GET_festivities, mdc-festivities-search): a API de eventos do core só com o selo
 * dos festejos, filtrando pelos campos do festejo. O mês da agenda vira a lista dos festejos com algum período naquele
 * mês (Hooks\Festivities::openTheFestivitySearch).
 */
class FestivitySearch
{
    // O parâmetro da API de eventos para o mês (1 a 12).
    const MONTH_PARAM = '@mdcMes';

    // O parâmetro para "Com Mestras e Mestres da Rede", pela regra da página do festejo (FestivityProfile::masters).
    const MASTERS_PARAM = '@mdcComMestres';

    /**
     * Só os festejos, com o selo; sem o selo configurado, nenhum.
     *
     * @return list<int> os festejos publicados com algum período que passa pelo mês (o de janeiro a março está em fevereiro)
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
               AND EXISTS (SELECT 1 FROM generate_series(date_trunc(\'month\', o.starts_on), date_trunc(\'month\', COALESCE(o.until, o.starts_on)), interval \'1 month\') m WHERE EXTRACT(MONTH FROM m) = :month)',
            ['type' => Event::class, 'seal' => $seal, 'enabled' => Event::STATUS_ENABLED, 'month' => $month]
        ));
    }

    /**
     * Os festejos publicados com alguma mestra ou mestre da Rede (o selo do programa): os marcados por quem cadastrou e
     * os que escolheram o festejo no cadastro aprovado.
     *
     * @return list<int>
     */
    static function idsWithMasters(): array
    {
        $festivity = (new FestivitySeal)->configuredId();
        $program = (new ProgramSeal)->configuredId();

        if (!$festivity || !$program) {
            return [];
        }

        $app = App::i();
        $params = ['event' => Event::class, 'agent' => Agent::class, 'festivity' => $festivity, 'program' => $program, 'enabled' => Event::STATUS_ENABLED];

        $ids = $app->conn->fetchFirstColumn(
            "SELECT DISTINCT e.id FROM event e
               JOIN seal_relation f ON f.object_id = e.id AND f.object_type = :event AND f.seal_id = :festivity
               JOIN event_meta m ON m.object_id = e.id AND m.key = :key
               JOIN seal_relation p ON p.object_type = :agent AND p.seal_id = :program AND m.value LIKE '%|' || p.object_id || '|%'
             WHERE e.status = :enabled",
            $params + ['key' => FestivityFields::MASTERS]
        );

        $fixed = new FixedOpportunity;
        $opportunity = $fixed->isConfigured() ? $fixed->get() : null;
        $keys = $opportunity ? FestivityField::keysOf($opportunity->id) : [];

        if ($keys) {
            $ids = [...$ids, ...$app->conn->fetchFirstColumn(
                'SELECT DISTINCT e.id FROM registration r
                   JOIN registration_meta m ON m.object_id = r.id AND m.key IN (:keys)
                   JOIN event e ON e.id::text = m.value AND e.status = :enabled
                   JOIN seal_relation f ON f.object_id = e.id AND f.object_type = :event AND f.seal_id = :festivity
                   JOIN seal_relation p ON p.object_id = r.agent_id AND p.object_type = :agent AND p.seal_id = :program
                 WHERE r.opportunity_id = :opportunity AND r.status = :approved',
                $params + ['keys' => $keys, 'opportunity' => $opportunity->id, 'approved' => Registration::STATUS_APPROVED],
                ['keys' => \Doctrine\DBAL\ArrayParameterType::STRING]
            )];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
