<?php

namespace Tests\MDC;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Entities\Node;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class TreeNodeTest extends TestCase
{
    use UserDirector;

    // Cada teste começa com a árvore vazia, dentro da própria transação.
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
    }

    private function loginAsManager(): void
    {
        $this->login($this->userDirector->createUser('admin'));
    }

    private function createNode(string $name, ?Node $parent = null): Node
    {
        $node = new Node;
        $node->name = $name;
        $node->parent = $parent;
        $node->save(true);

        return $node;
    }

    // Violar o índice pelo EntityManager fecha a conexão e derruba os testes seguintes.
    private function assertRefusesInsert(?int $parent_id, string $name, string $index): void
    {
        $connection = $this->app->em->getConnection();
        $connection->executeStatement('SAVEPOINT unique_check');

        try {
            $connection->executeStatement(
                'INSERT INTO mdc_tree_node (parent_id, name, level, display_order, status) VALUES (?, ?, 1, 1, ?)',
                [$parent_id, $name, Node::STATUS_ENABLED]
            );

            $connection->executeStatement('RELEASE SAVEPOINT unique_check');

            $this->fail("O índice {$index} deveria ter recusado a inserção de \"{$name}\"");
        } catch (UniqueConstraintViolationException $e) {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT unique_check');

            $this->assertStringContainsString($index, $e->getMessage(), 'A recusa veio de outro índice');
        }
    }

    function testSavesAndReadsARootNode()
    {
        $this->loginAsManager();

        $node = $this->createNode('Saberes e Ofícios');

        $found = $this->app->repo(Node::class)->find($node->id);

        $this->assertEquals('Saberes e Ofícios', $found->name);
        $this->assertNull($found->parent);
        $this->assertEquals(Node::STATUS_ENABLED, $found->status);
    }

    function testDerivesTheLevelFromTheParent()
    {
        $this->loginAsManager();

        $segmento = $this->createNode('Saberes e Ofícios');
        $tipologia = $this->createNode('Artesanato', $segmento);
        $subtipologia = $this->createNode('Couro', $tipologia);
        $atividade = $this->createNode('Bruacas', $subtipologia);

        $this->assertEquals(1, $segmento->level);
        $this->assertEquals(2, $tipologia->level);
        $this->assertEquals(3, $subtipologia->level);
        $this->assertEquals(4, $atividade->level);
    }

    /** @return Node[] Saberes e Ofícios › Artesanato › Couro › Bruacas */
    private function createBranch(): array
    {
        $segmento = $this->createNode('Saberes e Ofícios');
        $tipologia = $this->createNode('Artesanato', $segmento);
        $subtipologia = $this->createNode('Couro', $tipologia);
        $atividade = $this->createNode('Bruacas', $subtipologia);

        return [$segmento, $tipologia, $subtipologia, $atividade];
    }

    private function levelInTheDatabase(Node $node): int
    {
        return (int) $this->app->em->getConnection()->fetchOne('SELECT level FROM mdc_tree_node WHERE id = ?', [$node->id]);
    }

    function testSavingANodeLoadedFromTheDatabaseKeepsItsLevel()
    {
        $this->loginAsManager();
        [, , , $bruacas] = $this->createBranch();
        $this->app->em->clear();

        // Carregado do banco, o pai chega como proxy ainda não carregado.
        $loaded = $this->app->repo(Node::class)->find($bruacas->id);
        $loaded->displayOrder = 2;
        $loaded->save(true);

        $this->assertEquals(4, $this->levelInTheDatabase($loaded));
    }

    function testMovingANodeCarriesTheLevelsOfItsBranch()
    {
        $this->loginAsManager();
        [, , $couro, $bruacas] = $this->createBranch();
        $outro_segmento = $this->createNode('Celebrações e Rituais');

        $couro->parent = $outro_segmento;
        $couro->save(true);

        $this->assertEquals(2, $this->levelInTheDatabase($couro));
        $this->assertEquals(3, $this->levelInTheDatabase($bruacas), 'O que está abaixo do nó movido acompanha o novo nível');
        $this->assertEquals(3, $bruacas->level, 'O nó já carregado na requisição reflete o banco');
    }

    function testMovingANodeToTheRootMakesItASegment()
    {
        $this->loginAsManager();
        [, $artesanato, $couro, $bruacas] = $this->createBranch();

        $artesanato->parent = null;
        $artesanato->save(true);

        $this->assertEquals([1, 2, 3], [$this->levelInTheDatabase($artesanato), $this->levelInTheDatabase($couro), $this->levelInTheDatabase($bruacas)]);
    }

    function testMovingANodeLeavesTheOtherBranchesAndTheirHistoryAlone()
    {
        $this->loginAsManager();
        [$saberes, $artesanato, $couro, $bruacas] = $this->createBranch();
        $luthieria = $this->createNode('Luthieria', $saberes);
        $rabeca = $this->createNode('Rabeca', $luthieria);
        $revisions = count($bruacas->getRevisions());

        $couro->parent = $luthieria;
        $couro->save(true);

        $this->assertEquals(2, $this->levelInTheDatabase($artesanato), 'O antigo pai não muda');
        $this->assertEquals(3, $this->levelInTheDatabase($rabeca), 'O novo irmão não muda');
        $this->assertCount($revisions, $bruacas->getRevisions(), 'O nível é derivado: o ramo não ganha revisão por item');
    }

    function testReadsTheChildrenOfANode()
    {
        $this->loginAsManager();

        $segmento = $this->createNode('Saberes e Ofícios');
        $this->createNode('Artesanato', $segmento);
        $this->createNode('Luthieria', $segmento);

        $this->app->em->refresh($segmento);

        $this->assertCount(2, $segmento->children);
    }

    function testRefusesTwoChildrenWithTheSameNameUnderTheSameParent()
    {
        $this->loginAsManager();

        $segmento = $this->createNode('Saberes e Ofícios');
        $this->createNode('Artesanato', $segmento);

        $this->assertRefusesInsert($segmento->id, 'Artesanato', 'unq_mdc_tree_node_sibling');
    }

    function testRefusesTwoRootsWithTheSameName()
    {
        $this->loginAsManager();

        $this->createNode('Saberes e Ofícios');

        $this->assertRefusesInsert(null, 'Saberes e Ofícios', 'unq_mdc_tree_node_root');
    }

    function testAcceptsTheSameNameUnderDifferentParents()
    {
        $this->loginAsManager();

        $segmento = $this->createNode('Saberes e Ofícios');
        $arte_santeira = $this->createNode('Arte Santeira', $segmento);
        $escultura = $this->createNode('Escultura', $segmento);

        $primeira = $this->createNode('Cerâmica', $arte_santeira);
        $segunda = $this->createNode('Cerâmica', $escultura);

        $this->assertNotEquals($primeira->id, $segunda->id);
    }

    function testRecordsARevisionOnCreationAndOnChange()
    {
        $this->loginAsManager();

        $node = $this->createNode('Saberes e Ofícios');

        $this->assertCount(1, $node->getRevisions(), 'A criação precisa gerar revisão');

        $node->name = 'Saberes e Ofícios Tradicionais';
        $node->save(true);

        $this->assertCount(2, $node->getRevisions(), 'A alteração precisa gerar revisão');
    }

    function testAnOrdinaryUserCannotCreateANode()
    {
        $this->login($this->userDirector->createUser());

        $this->expectException(PermissionDenied::class);

        $this->createNode('Saberes e Ofícios');
    }

    function testAnOrdinaryUserCannotModifyANode()
    {
        $this->loginAsManager();
        $node = $this->createNode('Saberes e Ofícios');

        $this->login($this->userDirector->createUser());

        $node->name = 'Outro nome';

        $this->expectException(PermissionDenied::class);

        $node->save(true);
    }
}
