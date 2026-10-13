<?php

namespace MDC\Repositories;

use MapasCulturais\Entities\Registration;
use MDC\Dtos\CurationNode;
use MDC\Dtos\TreeNode;
use MDC\Entities\Node as NodeEntity;
use MDC\Services\ActingField;
use MDC\Services\TreeEquivalence;

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
     * Árvore inteira como a curadoria vê: com os desativados e o uso de cada item, sem a lixeira.
     *
     * @return CurationNode[]
     */
    function findCurationTree(): array
    {
        $nodes = $this->createQueryBuilder('n')
            ->where('n.status != :trash')
            ->setParameter('trash', NodeEntity::STATUS_TRASH)
            ->orderBy('n.level', 'ASC')->addOrderBy('n.displayOrder', 'ASC')->addOrderBy('n.id', 'ASC')
            ->getQuery()->getResult();

        return $this->nestForCuration($this->groupByParent($nodes), self::ROOT, $this->countUsage(), (new TreeEquivalence())->all());
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
     * Cadastros que marcaram cada item, pelo espelho gravado nas respostas da atuação, sem os da lixeira.
     *
     * @return array<int,array{sent: int, drafts: int}>
     */
    private function countUsage(): array
    {
        $rows = $this->_em->getConnection()->fetchAllAssociative(
            "SELECT (node->>'id')::int AS id,
                    COUNT(DISTINCT r.id) FILTER (WHERE r.status > :draft) AS sent,
                    COUNT(DISTINCT r.id) FILTER (WHERE r.status = :draft) AS drafts
               FROM registration_meta m
               JOIN registration r ON r.id = m.object_id
               JOIN registration_field_configuration f ON m.key = 'field_' || f.id AND f.field_type = :type
              CROSS JOIN LATERAL jsonb_array_elements(m.value::jsonb -> 'nodes') AS node
              WHERE m.value LIKE '{%'
              GROUP BY 1",
            ['draft' => Registration::STATUS_DRAFT, 'type' => ActingField::SLUG]
        );

        $usage = [];

        foreach ($rows as $row) {
            $usage[(int) $row['id']] = ['sent' => (int) $row['sent'], 'drafts' => (int) $row['drafts']];
        }

        return $usage;
    }

    /**
     * @param array<int,NodeEntity[]> $children_of
     * @param array<int,array{sent: int, drafts: int}> $usage
     * @param array<int,int> $equivalences item => referência
     * @return CurationNode[]
     */
    private function nestForCuration(array $children_of, int $parent_id, array $usage, array $equivalences): array
    {
        $branch = [];

        foreach ($children_of[$parent_id] ?? [] as $node) {
            $branch[] = new CurationNode(
                $node->id,
                $node->name,
                $node->status === NodeEntity::STATUS_ENABLED,
                $usage[$node->id]['sent'] ?? 0,
                $usage[$node->id]['drafts'] ?? 0,
                $equivalences[$node->id] ?? null,
                $this->nestForCuration($children_of, $node->id, $usage, $equivalences),
            );
        }

        return $branch;
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
