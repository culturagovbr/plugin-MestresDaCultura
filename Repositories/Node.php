<?php

namespace MDC\Repositories;

use MDC\Dtos\TreeNode;
use MDC\Entities\Node as NodeEntity;

class Node extends \MapasCulturais\Repository
{
    /** Chave que agrupa os nós sem pai, já que o índice de um array não pode ser nulo. */
    private const ROOT = 0;

    /**
     * Árvore inteira, aninhada, sem os nós removidos.
     *
     * @return TreeNode[]
     */
    function findTree(): array
    {
        $nodes = $this->findBy(
            ['status' => NodeEntity::STATUS_ENABLED],
            ['level' => 'ASC', 'displayOrder' => 'ASC', 'id' => 'ASC']
        );

        return $this->nest($this->groupByParent($nodes), self::ROOT);
    }

    /**
     * Nomes da raiz até o nó, inclusive. Vazio se o nó não existir.
     *
     * @return string[]
     */
    function findPath(int $id): array
    {
        $path = [];

        for ($node = $this->find($id); $node; $node = $node->parent) {
            array_unshift($path, $node->name);
        }

        return $path;
    }

    /**
     * Ids de toda a subárvore, sem o próprio nó. Inclui os removidos: é pergunta estrutural.
     *
     * @return int[]
     */
    function findDescendantIds(int $id): array
    {
        $children_of = $this->groupByParent($this->findAll());

        $descendants = [];
        $pending = $children_of[$id] ?? [];

        while ($pending) {
            $current = array_pop($pending);
            $descendants[] = $current->id;
            $pending = array_merge($pending, $children_of[$current->id] ?? []);
        }

        return $descendants;
    }

    /**
     * @param NodeEntity[] $nodes
     * @return array<int,NodeEntity[]>
     */
    private function groupByParent(array $nodes): array
    {
        $children_of = [];

        foreach ($nodes as $node) {
            $children_of[$node->parent ? $node->parent->id : self::ROOT][] = $node;
        }

        return $children_of;
    }

    /**
     * @param array<int,NodeEntity[]> $children_of
     * @return TreeNode[]
     */
    private function nest(array $children_of, int $parent_id): array
    {
        $branch = [];

        foreach ($children_of[$parent_id] ?? [] as $node) {
            $branch[] = new TreeNode($node->id, $node->name, $this->nest($children_of, $node->id));
        }

        return $branch;
    }
}
