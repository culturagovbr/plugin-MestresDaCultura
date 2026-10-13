<?php

namespace Tests\MDC;

use MDC\Dtos\TreeNode;
use MDC\Entities\Node;
use MDC\Repositories\Node as NodeRepository;
use MDC\Services\TreeExport;
use Tests\Abstract\TestCase;

// A árvore que a carga deixa no banco de testes.
class TreeLoadTest extends TestCase
{
    private const UPDATE = 'mdc: carrega a árvore de atuação';

    private function repo(): NodeRepository
    {
        return $this->app->repo(Node::class);
    }

    private function pluginFile(string $name): string
    {
        return dirname((new \ReflectionClass(\MDC\Plugin::class))->getFileName()) . "/{$name}";
    }

    /** @param TreeNode[] $nodes */
    private function shape(array $nodes): array
    {
        return array_map(fn (TreeNode $node) => ['name' => $node->name, 'children' => $this->shape($node->children)], $nodes);
    }

    private function jsonShape(array $items): array
    {
        return array_map(fn (array $item) => ['name' => $item['name'], 'children' => $this->jsonShape($item['children'] ?? [])], $items);
    }

    /** @param TreeNode[] $nodes */
    private function follow(array $nodes, array $path): ?TreeNode
    {
        $name = array_shift($path);

        foreach ($nodes as $node) {
            if ($node->name === $name) {
                return $path ? $this->follow($node->children, $path) : $node;
            }
        }

        return null;
    }

    function testLoadsEveryNodeOfTheSpreadsheet()
    {
        $this->assertSame(354, $this->repo()->count(['status' => Node::STATUS_ENABLED]));
        $this->assertCount(7, $this->repo()->findTree());
    }

    function testComputesTheLevelOfEachNode()
    {
        $levels = $this->app->em->getConnection()->fetchAllKeyValue(
            'SELECT level, COUNT(*) FROM mdc_tree_node GROUP BY level ORDER BY level'
        );

        $this->assertEquals([1 => 7, 2 => 129, 3 => 150, 4 => 68], $levels);
    }

    function testMatchesTheJsonFileInContentAndOrder()
    {
        $json = json_decode(file_get_contents($this->pluginFile('data/atuacao.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->jsonShape($json), $this->shape($this->repo()->findTree()));
    }

    // Sem curadoria, exportar devolve o próprio arquivo, byte a byte.
    function testExportsTheTreeExactlyAsTheJsonFile()
    {
        $this->assertSame(file_get_contents($this->pluginFile('data/atuacao.json')), TreeExport::json());
    }

    function testReachesNodesAtEveryDepth()
    {
        $tree = $this->repo()->findTree();

        foreach ([
            ['Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas'],
            ['Música e Instrumentos Populares', 'Zabumba', 'Zabumbeiro'],
            ['Identidades e Territórios', 'Cultura dos Geraizeiros'],
        ] as $path) {
            $node = $this->follow($tree, $path);

            $this->assertNotNull($node, implode(' > ', $path));
            $this->assertSame($path, $this->repo()->findPath($node->id));
        }
    }

    function testDoesNotRepeatTheParentAsItsOwnChild()
    {
        $batuqueiro = $this->follow($this->repo()->findTree(), ['Música e Instrumentos Populares', 'Batuqueiro']);

        $this->assertSame([], $batuqueiro->children);
    }

    function testRecordsTheCreationRevisionOfEachNode()
    {
        $missing = $this->app->em->getConnection()->fetchOne("
            SELECT COUNT(*) FROM mdc_tree_node n
             WHERE NOT EXISTS (
                SELECT 1 FROM entity_revision r
                 WHERE r.object_type = 'MDC\\Entities\\Node' AND r.object_id = n.id
             )");

        $this->assertEquals(0, $missing);
    }

    function testDoesNotLoadAgainOverAnExistingTree()
    {
        $updates = include $this->pluginFile('db-updates.php');

        $updates[self::UPDATE]();

        $this->assertSame(354, $this->repo()->count([]));
    }
}
