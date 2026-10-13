<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;
use MDC\Entities\Node;

/**
 * Histórico de um item da Árvore de Atuação, a partir das revisões do core: quem mudou o quê e quando, sem o nível.
 */
class TreeHistory
{
    /** @return array<array{at: string, who: string, changes: string[]}> do mais recente ao mais antigo */
    function forNode(Node $node): array
    {
        $rows = App::i()->em->getConnection()->fetchAllAssociative(
            'SELECT r.id, r.action, r.create_timestamp, a.name AS who, d.key, d.value
               FROM entity_revision r
               JOIN entity_revision_revision_data rd ON rd.revision_id = r.id
               JOIN entity_revision_data d ON d.id = rd.revision_data_id
               LEFT JOIN usr u ON u.id = r.user_id
               LEFT JOIN agent a ON a.id = u.profile_id
              WHERE r.object_type::text = ? AND r.object_id = ?
              ORDER BY r.id',
            [Node::class, $node->id]
        );

        $revisions = [];

        foreach ($rows as $row) {
            $revisions[$row['id']] ??= ['action' => $row['action'], 'at' => $row['create_timestamp'], 'who' => $row['who'], 'data' => []];
            $revisions[$row['id']]['data'][$row['key']] = json_decode($row['value'], true);
        }

        return self::describe(array_values($revisions));
    }

    /**
     * Compara cada retrato do item com o anterior e diz o que mudou; revisão sem mudança visível fica de fora.
     *
     * @param array<array{action: string, at: string, who: ?string, data: array}> $revisions do mais antigo ao mais recente
     * @return array<array{at: string, who: string, changes: string[]}> do mais recente ao mais antigo
     */
    static function describe(array $revisions): array
    {
        $entries = [];
        $before = null;

        foreach ($revisions as $revision) {
            $after = $revision['data'];
            $changes = $before === null ? [i::__('criou o item')] : self::changes($before, $after);
            $before = $after;

            if ($changes) {
                $entries[] = [
                    'at' => $revision['at'],
                    'who' => $revision['who'] ?: i::__('carga inicial'),
                    'changes' => $changes,
                ];
            }
        }

        return array_reverse($entries);
    }

    /** @return string[] */
    private static function changes(array $before, array $after): array
    {
        $changes = [];
        $place = fn ($parent) => $parent['name'] ?? i::__('Atuação Cultural (segmento)');

        if (($before['name'] ?? null) !== ($after['name'] ?? null)) {
            $changes[] = sprintf(i::__('renomeou de "%s" para "%s"'), $before['name'], $after['name']);
        }

        if (($before['parent']['id'] ?? null) !== ($after['parent']['id'] ?? null)) {
            $changes[] = sprintf(i::__('moveu de "%s" para "%s"'), $place($before['parent'] ?? null), $place($after['parent'] ?? null));
        } elseif (($before['displayOrder'] ?? null) !== ($after['displayOrder'] ?? null)) {
            $changes[] = i::__('mudou a ordem');
        }

        if (($before['status'] ?? null) !== ($after['status'] ?? null)) {
            $changes[] = (int) $after['status'] === Node::STATUS_ENABLED ? i::__('reativou') : i::__('desativou');
        }

        return $changes;
    }
}
