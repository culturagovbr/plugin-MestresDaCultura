<?php

namespace Tests\MDC;

use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;
use MDC\Services\TreeCuration;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class TreeCurationTest extends TestCase
{
    use UserDirector;

    private TreeCuration $curation;

    // Cada teste começa com a árvore vazia, dentro da própria transação.
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
        $this->login($this->userDirector->createUser('admin'));
        $this->curation = new TreeCuration();
    }

    /** @return Node[] Saberes e Ofícios › Artesanato › Couro › Bruacas */
    private function branch(): array
    {
        $saberes = $this->curation->add(null, 'Saberes e Ofícios');
        $artesanato = $this->curation->add($saberes, 'Artesanato');
        $couro = $this->curation->add($artesanato, 'Couro');
        $bruacas = $this->curation->add($couro, 'Bruacas');

        return [$saberes, $artesanato, $couro, $bruacas];
    }

    private function assertRefused(string $message, callable $action): void
    {
        try {
            $action();
            $this->fail("A ação deveria ter sido recusada: {$message}");
        } catch (CurationRefused $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function disable(Node $node): void
    {
        $node->status = Node::STATUS_DISABLED;
        $node->save(true);
    }

    /** @return string[] nomes dos filhos, na ordem da lista */
    private function namesUnder(?Node $parent): array
    {
        $children = $this->app->repo(Node::class)->findBy(['parent' => $parent], ['displayOrder' => 'ASC', 'id' => 'ASC']);

        return array_map(fn (Node $node) => $node->name, $children);
    }

    function testAddsAtTheEndOfTheListWithTheNameCleaned()
    {
        [$saberes] = $this->branch();
        $this->curation->add($saberes, 'Luthieria');

        $novo = $this->curation->add($saberes, "  Carpintarias   navais ");

        $this->assertSame('Carpintarias navais', $novo->name);
        $this->assertSame(['Artesanato', 'Luthieria', 'Carpintarias navais'], $this->namesUnder($saberes));
    }

    function testAddsASegmentAtTheRoot()
    {
        $this->branch();

        $segmento = $this->curation->add(null, 'Celebrações e Rituais');

        $this->assertNull($segmento->parent);
        $this->assertSame(1, $segmento->level);
    }

    function testRefusesToAddBelowAnActivity()
    {
        [, , , $bruacas] = $this->branch();

        $this->assertRefused('último nível', fn () => $this->curation->add($bruacas, 'Couro de bode'));
    }

    function testRefusesToAddBelowADisabledItem()
    {
        [, , $couro] = $this->branch();
        $this->disable($couro);

        $this->assertRefused('está desativado', fn () => $this->curation->add($couro, 'Charruas'));
    }

    function testRefusesTheSameNameInTheSamePlaceIgnoringCaseAndAccents()
    {
        [, $artesanato] = $this->branch();

        $this->assertRefused('Já existe "Couro"', fn () => $this->curation->add($artesanato, 'côuro'));
        $this->assertRefused('Escreva o nome', fn () => $this->curation->add($artesanato, '   '));
    }

    function testAcceptsANameThatExistsElsewhereOrOnlyAsDisabled()
    {
        [$saberes, $artesanato, $couro] = $this->branch();
        $escultura = $this->curation->add($saberes, 'Escultura');
        $this->disable($couro);

        $this->curation->add($escultura, 'Couro');
        $this->curation->add($artesanato, 'Couro');

        $this->assertSame(['Couro', 'Couro'], $this->namesUnder($artesanato), 'O desativado não conta: a nova entrada passa a ser a ativa');
    }

    function testRenamesToCorrectTheSpelling()
    {
        [, , $couro] = $this->branch();

        $this->curation->rename($couro, 'Couro  cru');

        $this->assertSame('Couro cru', $this->app->repo(Node::class)->find($couro->id)->name);
    }

    function testRefusesARenameThatChangesNothingCollidesOrTouchesADisabledItem()
    {
        [$saberes, $artesanato, $couro] = $this->branch();
        $luthieria = $this->curation->add($saberes, 'Luthieria');

        $this->assertRefused('não mudou', fn () => $this->curation->rename($couro, ' Couro '));
        $this->assertRefused('Já existe "Artesanato"', fn () => $this->curation->rename($luthieria, 'ARTESANATO'));

        $this->disable($couro);
        $this->assertRefused('está desativado', fn () => $this->curation->rename($couro, 'Couro cru'));
    }

    function testMovesUpAndDownAmongTheActiveSiblings()
    {
        [$saberes, $artesanato] = $this->branch();
        $luthieria = $this->curation->add($saberes, 'Luthieria');
        $culinaria = $this->curation->add($saberes, 'Culinária');

        $this->curation->reorder($culinaria, -1);
        $this->assertSame(['Artesanato', 'Culinária', 'Luthieria'], $this->namesUnder($saberes));

        // O desativado não conta como vizinho.
        $this->disable($culinaria);
        $this->curation->reorder($luthieria, -1);
        $this->assertSame(['Luthieria', 'Artesanato'], array_values(array_diff($this->namesUnder($saberes), ['Culinária'])));

        $this->assertRefused('Já é o primeiro', fn () => $this->curation->reorder($luthieria, -1));
        $this->assertRefused('Já é o último', fn () => $this->curation->reorder($artesanato, 1));
    }

    function testUntiesARepeatedOrderBeforeSwapping()
    {
        [$saberes, $artesanato] = $this->branch();
        $luthieria = $this->curation->add($saberes, 'Luthieria');
        $this->app->em->getConnection()->executeStatement('UPDATE mdc_tree_node SET display_order = 5 WHERE parent_id = ?', [$saberes->id]);
        $this->app->em->refresh($artesanato);
        $this->app->em->refresh($luthieria);

        $this->curation->reorder($luthieria, -1);

        $this->assertSame(['Luthieria', 'Artesanato'], $this->namesUnder($saberes));
    }

    function testAnOrdinaryUserCannotChangeTheTree()
    {
        [$saberes] = $this->branch();
        $this->login($this->userDirector->createUser());

        $this->expectException(PermissionDenied::class);

        $this->curation->add($saberes, 'Luthieria');
    }
}
