<?php

namespace MDC\Services;

use Doctrine\DBAL\ArrayParameterType;
use MapasCulturais\App;
use MapasCulturais\i;
use MDC\Entities\Node;

/**
 * Campo da pergunta "Atuação Cultural": marca nós da árvore e guarda o texto de cada "Outro".
 *
 * Valor gravado: `{"nodes": [ids], "others": [{"parent": id|null, "text": "..."}]}`.
 * Um nó marcado traz os pais marcados junto; `parent: null` é o "Outro" geral, fora de qualquer lista.
 */
class ActingField
{
    const SLUG = 'mdc-atuacao';

    const OTHER_MAX_LENGTH = 255;

    static function definition(): array
    {
        return [
            'slug' => self::SLUG,
            'name' => i::__('Árvore de atuação cultural'),
            'viewTemplate' => 'registration-field-types/' . self::SLUG,
            'configTemplate' => 'registration-field-types/' . self::SLUG . '-config',
            'serialize' => [self::class, 'serialize'],
            'unserialize' => [self::class, 'unserialize'],
            // Roda ao salvar; no envio, quem valida é o gancho de `Plugin::validateActingFieldOnSend`.
            'validations' => [
                '\MDC\Services\ActingField::isValid($value)' => self::invalidMessage(),
            ],
        ];
    }

    static function invalidMessage(): string
    {
        return i::__('A seleção de atuação tem itens inválidos. Recarregue a página e confira a seleção.');
    }

    // Só arruma a forma; o que estiver errado no conteúdo fica para `isValid` recusar.
    static function serialize(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        // O Vue manda objeto; a API do core às vezes entrega stdClass.
        $value = json_decode(json_encode($value), true);

        if (!is_array($value)) {
            return null;
        }

        $nodes = [];
        foreach ((array) ($value['nodes'] ?? []) as $id) {
            $nodes[] = is_numeric($id) ? (int) $id : $id;
        }

        $others = [];
        foreach ((array) ($value['others'] ?? []) as $other) {
            $text = is_array($other) && is_string($other['text'] ?? null) ? trim($other['text']) : '';

            if ($text === '') {
                continue;
            }

            $parent = $other['parent'] ?? null;
            $others[] = ['parent' => is_numeric($parent) ? (int) $parent : $parent, 'text' => $text];
        }

        // Vazio vira nulo: é o único valor que a validação de obrigatório do core reconhece.
        if (!$nodes && !$others) {
            return null;
        }

        return json_encode(['nodes' => array_values(array_unique($nodes, SORT_REGULAR)), 'others' => $others], JSON_UNESCAPED_UNICODE);
    }

    static function unserialize(mixed $value): ?array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    static function isValid(mixed $value): bool
    {
        $nodes = $value['nodes'] ?? null;
        $others = $value['others'] ?? null;

        if (!is_array($nodes) || !is_array($others)) {
            return false;
        }

        foreach ($nodes as $id) {
            if (!is_int($id)) {
                return false;
            }
        }

        $parent_of = self::activeParents($nodes);

        // Faltar alguém aqui é nó inexistente ou desativado pela curadoria.
        if (count($parent_of) !== count($nodes)) {
            return false;
        }

        foreach ($parent_of as $parent_id) {
            if ($parent_id !== null && !array_key_exists($parent_id, $parent_of)) {
                return false;
            }
        }

        foreach ($others as $other) {
            $text = $other['text'] ?? null;
            $parent = $other['parent'] ?? null;

            if (!is_string($text) || mb_strlen($text) > self::OTHER_MAX_LENGTH) {
                return false;
            }

            if ($parent !== null && (!is_int($parent) || !array_key_exists($parent, $parent_of))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int,int|null> id do nó => id do pai, só para os nós ativos */
    private static function activeParents(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $rows = App::i()->em->getConnection()->fetchAllAssociative(
            'SELECT id, parent_id FROM mdc_tree_node WHERE id IN (?) AND status = ?',
            [$ids, Node::STATUS_ENABLED],
            [ArrayParameterType::INTEGER]
        );

        $parent_of = [];

        foreach ($rows as $row) {
            $parent_of[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $parent_of;
    }
}
