<?php

namespace Tests\MDC;

use MDC\Dtos\OtherEntry;
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

    function testCutsThePageWithSearchFilterCountsAndOrder()
    {
        $decided = fn (string $type, ?int $node, string $at, ?string $note = null) => ['type' => $type, 'node' => $node, 'note' => $note, 'at' => $at];
        $entries = [
            new OtherEntry(168, 'coco de parelha', 'Coco de parelha', 3, 0),
            new OtherEntry(null, 'benzedura', 'Benzedura', 1, 0),
            new OtherEntry(168, 'coco de praia', 'Coco de praia', 5, 1),
            new OtherEntry(null, 'reza', 'Reza', 1, 0, [], $decided('associated', 80, '2026-10-02')),
            new OtherEntry(168, 'embolada', 'Embolada', 1, 0, [], $decided('created', 200, '2026-10-03')),
            new OtherEntry(null, 'teste', 'Teste', 1, 0, [], $decided('archived', null, '2026-10-01', 'Fora do escopo')),
        ];
        $paths = [168 => 'Expressões › Coco', 80 => 'Saberes › Benzedeira', 200 => 'Expressões › Coco › Embolada'];

        $page = OtherQueue::slice($entries, $paths, OtherQueue::PENDING, '', null, 1, 2);
        $this->assertSame(['coco de praia', 'coco de parelha'], array_map(fn ($e) => $e->key, $page['entries']), 'Os mais citados antes, cortados no limite');
        $this->assertSame([3, ['pending' => 3, 'decided' => 3]], [$page['count'], $page['totals']]);
        $this->assertSame(['benzedura'], array_map(fn ($e) => $e->key, OtherQueue::slice($entries, $paths, OtherQueue::PENDING, '', null, 2, 2)['entries']));

        $this->assertSame(['coco de praia', 'coco de parelha'], array_map(fn ($e) => $e->key, OtherQueue::slice($entries, $paths, OtherQueue::PENDING, 'COCO', null, 1, 20)['entries']), 'Busca pelo lugar onde foi escrito');

        $all = OtherQueue::slice($entries, $paths, OtherQueue::DECIDED, '', null, 1, 20);
        $this->assertSame(['embolada', 'reza', 'teste'], array_map(fn ($e) => $e->key, $all['entries']), 'Tratados: os mais recentes antes');
        $this->assertSame(['created' => 1, 'associated' => 1, 'archived' => 1], $all['counts']);

        $search = OtherQueue::slice($entries, $paths, OtherQueue::DECIDED, 'benzedeira', null, 1, 20);
        $this->assertSame([['reza'], ['created' => 0, 'associated' => 1, 'archived' => 0]], [array_map(fn ($e) => $e->key, $search['entries']), $search['counts']], 'Busca pelo item associado; as contagens seguem a busca');
        $this->assertSame(['teste'], array_map(fn ($e) => $e->key, OtherQueue::slice($entries, $paths, OtherQueue::DECIDED, 'escopo', 'archived', 1, 20)['entries']), 'Busca pelo motivo, com filtro');
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
