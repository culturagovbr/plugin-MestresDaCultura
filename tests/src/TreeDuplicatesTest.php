<?php

namespace Tests\MDC;

use MDC\Services\TreeCuration;
use MDC\Services\TreeDuplicates;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class TreeDuplicatesTest extends TestCase
{
    use UserDirector;

    private function node(int $id, string $name, array $ancestors = []): array
    {
        return ['id' => $id, 'name' => $name, 'ancestors' => $ancestors];
    }

    function testGroupsTheSameNameInDifferentPlacesButNotAlongTheSameBranch()
    {
        $nodes = [
            $this->node(1, 'Aboio', [100]),
            $this->node(2, 'aboio', [100, 101]),
            $this->node(3, 'Carpintarias', [200]),
            $this->node(4, 'Carpintaria', [200, 3]),
            $this->node(5, 'Terreiro', [300]),
            $this->node(6, 'Terreiro', [301]),
        ];

        $this->assertSame([1 => [2], 2 => [1], 5 => [6], 6 => [5]], TreeDuplicates::groups($nodes, []), 'Item e o que está abaixo dele não são duplicata');
        $this->assertSame([1 => [2], 2 => [1]], TreeDuplicates::groups($nodes, ['terreiro']), 'Repetição legítima deixa de ser apontada');
    }

    function testFindsNearlyEqualNamesWithoutConfusingDifferentOnes()
    {
        $nodes = [
            $this->node(1, 'Mineiro Pau', [100]),
            $this->node(2, 'Maneiro Pau', [101]),
            $this->node(3, 'Poesia Oral/Falada', [102]),
            $this->node(4, 'Poesia Oral ou Falada', [103]),
            $this->node(5, 'Boneco', [104]),
            $this->node(6, 'Bonecos', [105]),
            $this->node(7, 'Raizeira', [106]),
            $this->node(8, 'Caixeira', [107]),
            $this->node(9, 'Guerreiro', [108]),
        ];

        $groups = TreeDuplicates::groups($nodes, []);

        $this->assertSame([2], $groups[1] ?? null, 'Uma letra de diferença num nome de 8 letras ou mais');
        $this->assertSame([4], $groups[3] ?? null, 'As mesmas palavras, sem as curtas');
        $this->assertSame([6], $groups[5] ?? null, 'O plural não separa');
        $this->assertArrayNotHasKey(7, $groups, 'Duas letras de diferença não são o mesmo nome');
        $this->assertArrayNotHasKey(9, $groups);
        $this->assertEquals([1 => [2], 2 => [1]], array_intersect_key(TreeDuplicates::groups($nodes, ['mineiropau']), [1 => 0, 2 => 0]), 'Só some quando todas as chaves do grupo são legítimas');
    }

    function testMarksTheWholeGroupAsLegitimateAndUnmarksTheItem()
    {
        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_legit_repeat');
        $this->login($this->userDirector->createUser('admin'));
        $curation = new TreeCuration();
        $mineiro = $curation->add($curation->add(null, 'Expressões e Manifestações'), 'Mineiro Pau');
        $curation->add($curation->add(null, 'Celebrações e Rituais'), 'Maneiro Pau');
        $duplicates = new TreeDuplicates();

        $duplicates->markLegitimate($mineiro);

        $this->assertEqualsCanonicalizing(['mineiropau', 'maneiropau'], $duplicates->legitimateKeys());
        $this->assertSame([], $duplicates->forTree()['duplicates']);

        $duplicates->unmarkLegitimate($mineiro);

        $this->assertSame(['maneiropau'], $duplicates->legitimateKeys());
        $this->assertCount(2, $duplicates->forTree()['duplicates'], 'Voltar a alertar um nome reabre o grupo');
    }
}
