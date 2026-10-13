<?php

namespace Tests\MDC;

use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;
use MDC\Services\OtherQueue;
use MDC\Services\TreeCuration;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class OtherQueueTest extends TestCase
{
    use UserDirector;

    function testGroupsByPlaceAndTextCountingEachRegistrationOnce()
    {
        $rows = [
            ['registration' => 1, 'status' => 1, 'parent' => 9, 'text' => 'Coco de Parelha'],
            ['registration' => 2, 'status' => 1, 'parent' => 9, 'text' => 'coco  de parelha '],
            ['registration' => 3, 'status' => 0, 'parent' => 9, 'text' => 'Côco de Parelha'],
            ['registration' => 2, 'status' => 1, 'parent' => 9, 'text' => 'Coco de Parelha'],
            ['registration' => 4, 'status' => 1, 'parent' => 12, 'text' => 'Coco de Parelha'],
            ['registration' => 5, 'status' => 1, 'parent' => null, 'text' => 'Benzedura'],
            ['registration' => 6, 'status' => 1, 'parent' => null, 'text' => '   '],
        ];

        $groups = array_column(OtherQueue::group($rows), null, 'parent');

        $this->assertSame(['parent' => 9, 'key' => 'coco de parelha', 'text' => 'Coco de Parelha', 'sent' => 2, 'drafts' => 1], $groups[9], 'Caixa, acento e espaço não separam; o mesmo cadastro conta uma vez');
        $this->assertSame(1, $groups[12]['sent'], 'O mesmo texto em outro lugar é outro grupo');
        $this->assertSame('benzedura', $groups['']['key'], 'O "Não encontrou?" geral fica sem lugar; texto em branco não entra');
        $this->assertCount(3, $groups);
    }

    function testSuggestsSimilarItemsButNotThePlaceWhereItWasWritten()
    {
        $nodes = [
            ['id' => 168, 'name' => 'Coco', 'parent' => 152],
            ['id' => 175, 'name' => 'Coco de parea', 'parent' => 168],
            ['id' => 80, 'name' => 'Benzedeira', 'parent' => 79],
            ['id' => 124, 'name' => 'Caixeiro (a)', 'parent' => 122],
            ['id' => 183, 'name' => 'Congada / Congado / Congo', 'parent' => 152],
        ];

        $this->assertSame([175], OtherQueue::suggest('coco de parelha', 168, $nodes), 'O lugar onde foi escrito não é sugestão');
        $this->assertSame([80], OtherQueue::suggest('benzedura de quebranto', null, $nodes), 'Palavra longa com 2 letras de diferença');
        $this->assertSame([124], OtherQueue::suggest('caixeiro do reis', null, $nodes));
        $this->assertSame([], OtherQueue::suggest('couro de bode', 9, $nodes), 'Palavra curta só aceita 1 letra de diferença: "couro" não é "congo"');
    }

    function testRecordsOneDecisionPerTextAndCreatesTheItemWhereAsked()
    {
        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
        $this->login($this->userDirector->createUser('admin'));
        $curation = new TreeCuration();
        $expressoes = $curation->add(null, 'Expressões e Manifestações');
        $coco = $curation->add($expressoes, 'Coco');
        $queue = new OtherQueue();

        $created = $queue->create($coco, 'Coco de Parelha', 'Coco de Parelha', $coco);
        $queue->associate(null, 'Benzedura', $coco);
        $queue->archive(null, 'Qualquer coisa', 'Não é atuação cultural');

        $this->assertSame($coco->id, $created->parent->id);
        $this->assertSame(Node::STATUS_ENABLED, $created->status);
        $this->assertSame(
            [['created', $created->id, null], ['associated', $coco->id, null], ['archived', null, 'Não é atuação cultural']],
            array_map(fn ($row) => [$row['decision'], $row['node_id'] === null ? null : (int) $row['node_id'], $row['note']], $this->app->em->getConnection()->fetchAllAssociative('SELECT decision, node_id, note FROM mdc_tree_other_review ORDER BY id'))
        );

        $this->expectException(CurationRefused::class);
        $queue->archive($coco, 'coco de parelha');
    }
}
