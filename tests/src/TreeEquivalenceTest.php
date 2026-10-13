<?php

namespace Tests\MDC;

use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;
use MDC\Services\TreeCuration;
use MDC\Services\TreeEquivalence;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class TreeEquivalenceTest extends TestCase
{
    use UserDirector;

    private TreeEquivalence $equivalence;

    // Cada teste começa com a árvore e as equivalências vazias, dentro da própria transação.
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
        $this->login($this->userDirector->createUser('admin'));
        $this->equivalence = new TreeEquivalence();
    }

    /** @return Node[] Afoxé (tipologia), Carnaval › Afoxé e Romaria, em Celebrações e Rituais */
    private function duplicates(): array
    {
        $curation = new TreeCuration();
        $celebracoes = $curation->add(null, 'Celebrações e Rituais');
        $afoxe = $curation->add($celebracoes, 'Afoxé');
        $carnaval = $curation->add($celebracoes, 'Carnaval');

        return [$afoxe, $curation->add($carnaval, 'Afoxé'), $curation->add($celebracoes, 'Romaria')];
    }

    private function assertRefused(string $message, callable $action): void
    {
        try {
            $action();
            $this->fail("A equivalência deveria ter sido recusada: {$message}");
        } catch (CurationRefused $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    function testMarksAndUndoesAnEquivalenceShownInTheCurationTree()
    {
        [$afoxe, $afoxeDoCarnaval] = $this->duplicates();

        $this->equivalence->mark($afoxeDoCarnaval, $afoxe);

        $carnaval = $this->app->repo(Node::class)->findCurationTree()[0]->children[1];
        $this->assertSame($afoxe->id, $carnaval->children[0]->equivalentTo);
        $this->assertSame([$afoxeDoCarnaval->id => $afoxe->id], $this->equivalence->all());

        $this->equivalence->undo($afoxeDoCarnaval);
        $this->assertSame([], $this->equivalence->all());
    }

    function testRefusesSelfReferenceChainsAndASecondReference()
    {
        [$afoxe, $afoxeDoCarnaval, $romaria] = $this->duplicates();
        $this->equivalence->mark($afoxeDoCarnaval, $afoxe);

        $this->assertRefused('não equivale a ele mesmo', fn () => $this->equivalence->mark($romaria, $romaria));
        $this->assertRefused('desfaça antes', fn () => $this->equivalence->mark($afoxeDoCarnaval, $romaria));
        $this->assertRefused('marque a equivalência direto com "Afoxé"', fn () => $this->equivalence->mark($romaria, $afoxeDoCarnaval));
        $this->assertRefused('ele é referência', fn () => $this->equivalence->mark($afoxe, $romaria));
        $this->assertRefused('não equivale a nenhum item', fn () => $this->equivalence->undo($romaria));
    }

    function testAnOrdinaryUserCannotMarkAnEquivalence()
    {
        [$afoxe, $afoxeDoCarnaval] = $this->duplicates();
        $this->login($this->userDirector->createUser());

        $this->expectException(PermissionDenied::class);

        $this->equivalence->mark($afoxeDoCarnaval, $afoxe);
    }
}
