<?php

namespace Tests\MDC;

use MDC\Entities\Node;
use MDC\Repositories\Node as NodeRepository;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class NodeRepositoryTest extends TestCase
{
    use UserDirector;

    // Cada teste começa com a árvore vazia, dentro da própria transação.
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
    }

    private function repo(): NodeRepository
    {
        return $this->app->repo(Node::class);
    }

    private function createNode(string $name, ?Node $parent = null, int $order = 1): Node
    {
        $node = new Node;
        $node->name = $name;
        $node->parent = $parent;
        $node->displayOrder = $order;
        $node->save(true);

        return $node;
    }

    private function seed(): array
    {
        $this->login($this->userDirector->createUser('admin'));

        $segmento = $this->createNode('Saberes e Ofícios');
        $tipologia = $this->createNode('Artesanato', $segmento);
        $subtipologia = $this->createNode('Couro', $tipologia);
        $atividade = $this->createNode('Bruacas', $subtipologia);

        return compact('segmento', 'tipologia', 'subtipologia', 'atividade');
    }

    function testReadsTheWholeTreeNested()
    {
        $this->seed();

        $tree = $this->repo()->findTree();

        $this->assertCount(1, $tree);
        $this->assertEquals('Saberes e Ofícios', $tree[0]->name);
        $this->assertEquals('Artesanato', $tree[0]->children[0]->name);
        $this->assertEquals('Couro', $tree[0]->children[0]->children[0]->name);
        $this->assertEquals('Bruacas', $tree[0]->children[0]->children[0]->children[0]->name);
        $this->assertSame([], $tree[0]->children[0]->children[0]->children[0]->children);
    }

    function testOrdersSiblingsByDisplayOrderAndNotByInsertion()
    {
        $this->login($this->userDirector->createUser('admin'));

        $segmento = $this->createNode('Saberes e Ofícios');
        $this->createNode('Luthieria', $segmento, order: 2);
        $this->createNode('Artesanato', $segmento, order: 1);

        $tipologias = $this->repo()->findTree()[0]->children;

        $this->assertEquals(['Artesanato', 'Luthieria'], array_map(fn ($n) => $n->name, $tipologias));
    }

    function testDoesNotReadRemovedNodes()
    {
        $seeded = $this->seed();

        $seeded['tipologia']->delete(true);

        $tree = $this->repo()->findTree();

        $this->assertSame([], $tree[0]->children, 'O nó removido não pode aparecer na leitura');
    }

    function testDoesNotReadNodesLeftOrphanByARemoval()
    {
        $seeded = $this->seed();

        $seeded['tipologia']->delete(true);

        $tree = $this->repo()->findTree();

        $this->assertCount(1, $tree, 'A subtipologia órfã não pode virar raiz');
    }

    function testReturnsThePathOfADeepNode()
    {
        $seeded = $this->seed();

        $path = $this->repo()->findPath($seeded['atividade']->id);

        $this->assertEquals(['Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas'], $path);
    }

    function testReturnsThePathOfAShallowNode()
    {
        $seeded = $this->seed();

        $path = $this->repo()->findPath($seeded['tipologia']->id);

        $this->assertEquals(['Saberes e Ofícios', 'Artesanato'], $path);
    }

    function testReturnsAnEmptyPathForANodeThatDoesNotExist()
    {
        $this->assertSame([], $this->repo()->findPath(999999999));
    }

    function testReturnsTheDescendantsOfANode()
    {
        $seeded = $this->seed();

        $descendants = $this->repo()->findDescendantIds($seeded['segmento']->id);

        sort($descendants);

        $expected = [$seeded['tipologia']->id, $seeded['subtipologia']->id, $seeded['atividade']->id];
        sort($expected);

        $this->assertEquals($expected, $descendants);
    }

    function testReturnsNoDescendantsForALeaf()
    {
        $seeded = $this->seed();

        $this->assertSame([], $this->repo()->findDescendantIds($seeded['atividade']->id));
    }
}
