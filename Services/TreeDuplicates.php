<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;

/**
 * Possíveis duplicatas da Árvore de Atuação: itens ativos com o mesmo nome, ou quase, em lugares
 * diferentes. Nome repetido de propósito (o mesmo material em vários ofícios) é marcado como repetição
 * legítima e deixa de ser apontado; duplicata de verdade se resolve com equivalência e desativação.
 */
class TreeDuplicates
{
    /**
     * @return array{duplicates: array<int,int[]>, legitimate: int[]} os apontados (id => outros do grupo) e
     *     os que se repetem de propósito
     */
    function forTree(): array
    {
        $nodes = $this->activeNodes();
        $duplicates = self::groups($nodes, $this->legitimateKeys());

        return ['duplicates' => $duplicates, 'legitimate' => array_keys(array_diff_key(self::groups($nodes, []), $duplicates))];
    }

    /** @return string[] chaves marcadas como repetição legítima */
    function legitimateKeys(): array
    {
        return App::i()->em->getConnection()->fetchFirstColumn('SELECT name_key FROM mdc_tree_legit_repeat');
    }

    // O item e os nomes do grupo dele deixam de ser apontados.
    function markLegitimate(Node $node): void
    {
        $this->requireCurator($node);

        $group = self::groups($this->activeNodes(), [])[$node->id] ?? null;

        if ($group === null) {
            throw new CurationRefused(sprintf(i::__('"%s" não se repete em outro lugar.'), $node->name));
        }

        $names = App::i()->repo(Node::class)->findBy(['id' => [$node->id, ...$group]]);

        foreach (array_unique(array_map(fn (Node $item) => self::key($item->name), $names)) as $key) {
            App::i()->em->getConnection()->executeStatement(
                'INSERT INTO mdc_tree_legit_repeat (name_key, user_id) VALUES (?, ?) ON CONFLICT (name_key) DO NOTHING',
                [$key, App::i()->user->id]
            );
        }
    }

    function unmarkLegitimate(Node $node): void
    {
        $this->requireCurator($node);

        if (!App::i()->em->getConnection()->delete('mdc_tree_legit_repeat', ['name_key' => self::key($node->name)])) {
            throw new CurationRefused(sprintf(i::__('"%s" não está marcado como repetição legítima.'), $node->name));
        }
    }

    /**
     * Agrupa itens iguais ou quase: mesma chave, até 1 letra de diferença em nomes de 8 letras ou mais, ou as mesmas
     * palavras sem as curtas e sem plural. Nunca um item com o que está acima ou abaixo dele; grupo só de chaves
     * legítimas não é apontado.
     *
     * @param array<array{id: int, name: string, ancestors: int[]}> $nodes
     * @param string[] $legitimate chaves marcadas como repetição legítima
     * @return array<int,int[]> id => ids dos outros itens do grupo
     */
    static function groups(array $nodes, array $legitimate): array
    {
        $root = array_combine(array_column($nodes, 'id'), array_column($nodes, 'id'));
        $find = function (int $id) use (&$root, &$find): int {
            return $root[$id] === $id ? $id : $root[$id] = $find($root[$id]);
        };

        $prepared = array_map(fn ($node) => $node + ['key' => self::key($node['name']), 'words' => self::words($node['name'])], $nodes);

        foreach ($prepared as $i => $a) {
            foreach (array_slice($prepared, $i + 1) as $b) {
                $related = in_array($a['id'], $b['ancestors'], true) || in_array($b['id'], $a['ancestors'], true);

                if (!$related && self::alike($a, $b)) {
                    $root[$find($a['id'])] = $find($b['id']);
                }
            }
        }

        $members = [];
        foreach ($prepared as $node) {
            $members[$find($node['id'])][] = $node;
        }

        $groups = [];
        foreach ($members as $group) {
            $keys = array_unique(array_column($group, 'key'));

            if (count($group) < 2 || !array_diff($keys, $legitimate)) {
                continue;
            }

            $ids = array_column($group, 'id');
            foreach ($ids as $id) {
                $groups[$id] = array_values(array_diff($ids, [$id]));
            }
        }

        return $groups;
    }

    /** Sem acento, espaço nem pontuação: "Compositor (a)" vira "compositora". */
    static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', TreeText::comparable($name));
    }

    private static function alike(array $a, array $b): bool
    {
        if ($a['key'] === $b['key']) {
            return true;
        }

        if (min(strlen($a['key']), strlen($b['key'])) >= 8 && levenshtein($a['key'], $b['key']) <= 1) {
            return true;
        }

        return $a['words'] && $a['words'] === $b['words'];
    }

    /** @return string[] palavras de 3 letras ou mais, sem o plural, em ordem */
    private static function words(string $name): array
    {
        $words = array_filter(preg_split('/[^a-z0-9]+/', TreeText::comparable($name)), fn ($word) => strlen($word) >= 3);
        $words = array_map(fn ($word) => preg_replace('/s$/', '', $word), $words);
        sort($words);

        return array_values($words);
    }

    /** @return array<array{id: int, name: string, ancestors: int[]}> */
    private function activeNodes(): array
    {
        $rows = App::i()->em->getConnection()->fetchAllAssociative('SELECT id, parent_id, name, status FROM mdc_tree_node WHERE status != ?', [Node::STATUS_TRASH]);
        $parentOf = array_column($rows, 'parent_id', 'id');
        $nodes = [];

        foreach ($rows as $row) {
            if ((int) $row['status'] !== Node::STATUS_ENABLED) {
                continue;
            }

            $ancestors = [];
            for ($parent = $parentOf[$row['id']]; $parent !== null; $parent = $parentOf[$parent] ?? null) {
                $ancestors[] = (int) $parent;
            }

            $nodes[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'ancestors' => $ancestors];
        }

        return $nodes;
    }

    // A tabela não é entidade: a permissão é a mesma de alterar a árvore.
    private function requireCurator(Node $node): void
    {
        if (!App::i()->user->is('admin')) {
            throw new PermissionDenied(App::i()->user, $node, 'modify');
        }
    }
}
