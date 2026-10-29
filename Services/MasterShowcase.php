<?php

namespace MDC\Services;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Registration;

/**
 * Vitrine da home: as mestras e os mestres com o selo do programa, os aprovados mais recentes primeiro, e os
 * contadores. Só o que o perfil público já mostra (MasterProfile), com uma consulta para a lista e outra para as fotos.
 */
class MasterShowcase
{
    // A UF no fim da cidade, gravada como "Olinda, PE" (em JSON, entre aspas).
    private const UF = "substring(city.value from ',\\s*([A-Z]{2})\"?\\s*$')";

    // Quantos aprovados mais recentes a vitrine mostra.
    const HIGHLIGHTS = 6;

    // Tamanho do resumo do cartão: a mini bio ou o começo da descrição longa.
    private const SUMMARY = 160;

    function __construct(private ?FixedOpportunity $opportunity = null, private ?ProgramSeal $seal = null)
    {
        $this->opportunity ??= new FixedOpportunity;
        $this->seal ??= new ProgramSeal;
    }

    /** @return array<array{id: int, name: string, knownAs: string, activities: string[], city: string, summary: string, avatar: ?string, url: string}> */
    function highlights(int $limit = self::HIGHLIGHTS): array
    {
        if (!$query = $this->query()) {
            return [];
        }

        [$sql, $params] = $query;
        $rows = App::i()->conn->fetchAllAssociative(
            "SELECT a.id, a.name, a.short_description, a.long_description, social.value AS known_as, acting.value AS acting, city.value AS city {$sql}
              ORDER BY sr.create_timestamp DESC, sr.id DESC LIMIT :limit",
            $params + ['limit' => $limit]
        );
        $avatars = $this->avatars(array_map('intval', array_column($rows, 'id')));

        return array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'knownAs' => (string) $row['known_as'],
            'activities' => array_slice(MasterProfile::activities((array) ActingField::unserialize($row['acting'])), 0, MasterProfile::HEADER_ACTIVITIES),
            'city' => self::text($row['city']),
            'summary' => self::summary((string) $row['short_description'], (string) $row['long_description']),
            'avatar' => $avatars[(int) $row['id']] ?? null,
            'url' => App::i()->createUrl('agent', 'single', [(int) $row['id']]),
        ], $rows);
    }

    /** @return array{masters: int, states: int} */
    function counts(): array
    {
        if (!$query = $this->query()) {
            return ['masters' => 0, 'states' => 0];
        }

        [$sql, $params] = $query;
        $row = App::i()->conn->fetchAssociative('SELECT count(DISTINCT a.id) AS masters, count(DISTINCT ' . self::UF . ") AS states {$sql}", $params);

        return ['masters' => (int) $row['masters'], 'states' => (int) $row['states']];
    }

    /**
     * Quantos em cada UF, para o mapa da Rede, na mesma base dos contadores e sem a localização de ninguém.
     *
     * @return array<string, int> UF => quantidade, só as que têm alguém
     */
    function byState(): array
    {
        if (!$query = $this->query()) {
            return [];
        }

        [$sql, $params] = $query;
        $rows = App::i()->conn->fetchAllKeyValue('SELECT ' . self::UF . " AS uf, count(DISTINCT a.id) {$sql} AND city.value IS NOT NULL GROUP BY 1 ORDER BY 1", $params);

        return array_map('intval', array_filter($rows, fn ($uf) => (bool) $uf, ARRAY_FILTER_USE_KEY));
    }

    /**
     * O que a lista e os contadores dividem: o agente com o selo, ativo, e a inscrição aprovada dele no programa, com
     * a atuação e a cidade. Sem o selo ou a oportunidade configurados, nada.
     *
     * @return ?array{0: string, 1: array}
     */
    private function query(): ?array
    {
        $sealId = $this->seal->configuredId();
        $opportunity = $this->opportunity->isConfigured() ? $this->opportunity->get() : null;

        if (!$sealId || !$opportunity) {
            return null;
        }

        $fields = ['acting' => '', 'city' => ''];
        foreach ($opportunity->registrationFieldConfigurations as $field) {
            if ($field->fieldType === ActingField::SLUG && !$fields['acting']) {
                $fields['acting'] = $field->fieldName;
            } elseif ($field->fieldType === 'municipio' && !$fields['city']) {
                $fields['city'] = $field->fieldName;
            }
        }

        $sql = "FROM seal_relation sr
                JOIN agent a ON a.id = sr.object_id AND a.status = :enabled
                LEFT JOIN agent_meta social ON social.object_id = a.id AND social.key = :knownAs
                LEFT JOIN registration r ON r.agent_id = a.id AND r.opportunity_id = :opportunity AND r.status = :approved
                LEFT JOIN registration_meta acting ON acting.object_id = r.id AND acting.key = :acting
                LEFT JOIN registration_meta city ON city.object_id = r.id AND city.key = :city
               WHERE sr.seal_id = :seal AND sr.object_type = :agent AND sr.status > 0";

        return [$sql, [
            'enabled' => Agent::STATUS_ENABLED,
            'opportunity' => $opportunity->id,
            'approved' => Registration::STATUS_APPROVED,
            'acting' => $fields['acting'],
            'city' => $fields['city'],
            'seal' => $sealId,
            'agent' => Agent::class,
            // A cópia moderada do nome artístico, feita na aprovação.
            'knownAs' => MasterPublicData::KNOWN_AS,
        ]];
    }

    /**
     * A foto de cada agente, na versão reduzida, numa consulta só à API do core.
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    private function avatars(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $agents = (new ApiQuery(Agent::class, ['@select' => 'id,files.avatar', 'id' => 'IN(' . implode(',', $ids) . ')']))->find();
        $avatars = [];

        foreach ($agents as $agent) {
            // A API entrega a foto e as versões dela como objetos, num array de transformações.
            $avatar = $agent['files']['avatar'] ?? null;
            $medium = is_object($avatar) ? ($avatar->transformations['avatarMedium'] ?? null) : null;
            $url = $medium->url ?? $avatar->url ?? null;
            if ($url) {
                $avatars[(int) $agent['id']] = $url;
            }
        }

        return $avatars;
    }

    private static function summary(string $short, string $long): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($short ?: $long)));

        return mb_strlen($text) > self::SUMMARY ? rtrim(mb_substr($text, 0, self::SUMMARY - 1)) . '…' : $text;
    }

    // Lê a cidade em texto JSON ("\"Olinda, PE\"") ou em texto puro.
    private static function text(?string $value): string
    {
        $decoded = json_decode((string) $value, true);

        return is_string($decoded) ? $decoded : (string) $value;
    }
}
