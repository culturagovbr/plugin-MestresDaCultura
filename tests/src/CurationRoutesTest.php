<?php

namespace Tests\MDC;

use MDC\Entities\Node;
use MDC\Services\TreeCuration;
use MDC\Services\TreeExport;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * Rotas da curadoria (controller `mdc`): quem chega a elas e o que cada resposta traz.
 */
class CurationRoutesTest extends TestCase
{
    use RequestFactory;
    use UserDirector;

    // Cada teste começa com a árvore vazia, dentro da própria transação.
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');
        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_legit_repeat');
    }

    /** @return array{0: int, 1: mixed} status e corpo (JSON decodificado) */
    private function call(ServerRequestInterface $request): array
    {
        $this->app->reset();
        $this->app->run($request, false);
        $body = (string) $this->app->response->getBody();

        return [$this->app->response->getStatusCode(), json_decode($body, true) ?? $body];
    }

    private function get(string $action, array $query = []): array
    {
        return $this->call($this->requestFactory->GET('mdc', $action, query_params: $query, ajax: true));
    }

    private function post(string $action, array $payload = []): array
    {
        return $this->call($this->requestFactory->POST('mdc', $action, payload: $payload));
    }

    private function asAdmin(): void
    {
        $this->login($this->userDirector->createUser('admin'));
    }

    /** @return Node[] Celebrações e Rituais com Afoxé, Romaria e Carnaval › Afoxé */
    private function tree(): array
    {
        $curation = new TreeCuration();
        $celebracoes = $curation->add(null, 'Celebrações e Rituais');
        $afoxe = $curation->add($celebracoes, 'Afoxé');
        $romaria = $curation->add($celebracoes, 'Romaria');
        $carnaval = $curation->add($celebracoes, 'Carnaval');

        return [$celebracoes, $afoxe, $romaria, $carnaval, $curation->add($carnaval, 'Afoxé')];
    }

    /** @return array<string,mixed> item da árvore da curadoria pelo id, onde estiver */
    private function curationNode(array $tree, int $id): ?array
    {
        foreach ($tree as $node) {
            if ($node['id'] === $id) {
                return $node;
            }

            if ($found = $this->curationNode($node['children'], $id)) {
                return $found;
            }
        }

        return null;
    }

    function testOnlyAdministratorsReachTheCuration()
    {
        $this->asAdmin();
        [, $afoxe] = $this->tree();
        $this->logout();

        $this->assertSame(401, $this->get('curationTree')[0], 'Visitante precisa entrar');
        $this->assertSame(401, $this->post('curationAdd', ['name' => 'Coco'])[0]);

        $this->login($this->userDirector->createUser());
        $routes = [
            'GET curationTree' => $this->get('curationTree'),
            'GET curationOthers' => $this->get('curationOthers'),
            'GET curationExport' => $this->get('curationExport'),
            'GET curationHistory' => $this->get('curationHistory', ['id' => $afoxe->id]),
            'POST curationAdd' => $this->post('curationAdd', ['name' => 'Coco']),
            'POST curationRename' => $this->post('curationRename', ['id' => $afoxe->id, 'name' => 'Afoxés']),
            'POST curationDisable' => $this->post('curationDisable', ['id' => $afoxe->id]),
            'POST curationLegitimate' => $this->post('curationLegitimate', ['id' => $afoxe->id]),
            'POST curationOtherArchive' => $this->post('curationOtherArchive', ['key' => 'qualquer coisa']),
        ];

        foreach ($routes as $route => [$status]) {
            $this->assertSame(403, $status, "{$route} é só de administrador");
        }

        $this->assertSame('Afoxé', $afoxe->name, 'Barrado antes de a ação mexer no item');
        $this->assertSame(
            [['name' => 'Afoxé', 'status' => Node::STATUS_ENABLED]],
            $this->app->em->getConnection()->fetchAllAssociative('SELECT name, status FROM mdc_tree_node WHERE id = ?', [$afoxe->id]),
            'Nada foi gravado'
        );
        $this->assertSame(0, (int) $this->app->em->getConnection()->fetchOne('SELECT count(*) FROM mdc_tree_legit_repeat'));
    }

    function testChangesTheTreeThroughTheRoutesAndReadsItBack()
    {
        $this->asAdmin();
        [$celebracoes, $afoxe, $romaria, $carnaval] = $this->tree();

        [$status, $created] = $this->post('curationAdd', ['parent' => $celebracoes->id, 'name' => '  Folia   de Reis ']);
        $this->assertSame(200, $status);
        $this->assertSame(['id' => $created['id']], $created, 'Adicionar devolve o id do item novo');

        $this->assertSame([200, true], $this->post('curationRename', ['id' => $created['id'], 'name' => 'Folia de Reis (Reisado)']));
        $this->assertSame([200, true], $this->post('curationReorder', ['id' => $romaria->id, 'offset' => -1]));
        $this->assertSame([200, true], $this->post('curationMove', ['id' => $created['id'], 'parent' => $carnaval->id]));
        $this->assertSame([200, true], $this->post('curationDisable', ['id' => $afoxe->id]));

        [$status, $tree] = $this->get('curationTree');
        $this->assertSame(200, $status);
        $this->assertSame(['Romaria', 'Afoxé', 'Carnaval'], array_column($tree[0]['children'], 'name'), 'Romaria subiu uma posição');
        $this->assertFalse($this->curationNode($tree, $afoxe->id)['enabled'], 'O desativado continua na árvore da curadoria');
        $this->assertSame('Folia de Reis (Reisado)', $this->curationNode($tree, $carnaval->id)['children'][1]['name'], 'Renomeado e movido para o fim de Carnaval');

        $this->assertSame([200, true], $this->post('curationEnable', ['id' => $afoxe->id]));
        $this->assertTrue($this->curationNode($this->get('curationTree')[1], $afoxe->id)['enabled']);

        [, $segment] = $this->post('curationAdd', ['name' => 'Teatro e Brincadeiras']);
        $this->assertNull($this->app->repo(Node::class)->find($segment['id'])->parent, 'Sem pai, o item vira segmento');
    }

    function testRefusalComesBackWithTheMessageForTheScreen()
    {
        $this->asAdmin();
        [$celebracoes, $afoxe, $romaria, , $afoxeDoCarnaval] = $this->tree();

        $this->assertSame([400, ['error' => true, 'data' => 'Escreva o nome.']], $this->post('curationAdd', ['parent' => $celebracoes->id, 'name' => '   ']));
        $this->assertSame([400, ['error' => true, 'data' => 'Já existe "Afoxé" neste mesmo lugar.']], $this->post('curationRename', ['id' => $romaria->id, 'name' => 'afoxe']));
        $this->assertSame([400, ['error' => true, 'data' => 'Um item não pode ir para dentro do próprio ramo.']], $this->post('curationMove', ['id' => $celebracoes->id, 'parent' => $afoxe->id]));
        $this->assertSame(400, $this->post('curationEquivalence', ['id' => $afoxe->id, 'to' => $afoxe->id])[0], 'Item não equivale a si mesmo');

        $this->assertSame([200, true], $this->post('curationEquivalence', ['id' => $afoxeDoCarnaval->id, 'to' => $afoxe->id]));
        $this->assertSame(400, $this->post('curationEquivalence', ['id' => $afoxeDoCarnaval->id, 'to' => $romaria->id])[0], 'Já marcado');
        $this->assertSame('Romaria', $this->app->repo(Node::class)->find($romaria->id)->name, 'A recusa não grava nada');
    }

    function testUnknownOrTrashedItemIsNotFound()
    {
        $this->asAdmin();
        [, $afoxe] = $this->tree();
        $afoxe->status = Node::STATUS_TRASH;
        $afoxe->save(true);

        $this->assertSame(404, $this->post('curationRename', ['id' => 999999, 'name' => 'Coco'])[0]);
        $this->assertSame(404, $this->post('curationDisable', ['id' => $afoxe->id])[0], 'Item na lixeira não volta pela curadoria');
        $this->assertSame(404, $this->post('curationAdd', ['parent' => 999999, 'name' => 'Coco'])[0], 'Pai inexistente não vira segmento');
        $this->assertSame(404, $this->post('curationMove', ['id' => 999999])[0]);
        $this->assertSame(404, $this->get('curationHistory', ['id' => 999999])[0]);
    }

    function testMarksAndUndoesEquivalencesAndLegitimateRepetitions()
    {
        $this->asAdmin();
        [, $afoxe, , , $afoxeDoCarnaval] = $this->tree();

        $node = fn (int $id) => $this->curationNode($this->get('curationTree')[1], $id);

        $this->assertSame([$afoxeDoCarnaval->id], $node($afoxe->id)['duplicates'], 'O mesmo nome em dois lugares é apontado');

        $this->assertSame([200, true], $this->post('curationEquivalence', ['id' => $afoxeDoCarnaval->id, 'to' => $afoxe->id]));
        $this->assertSame($afoxe->id, $node($afoxeDoCarnaval->id)['equivalentTo']);
        $this->assertSame([200, true], $this->post('curationEquivalenceUndo', ['id' => $afoxeDoCarnaval->id]));
        $this->assertNull($node($afoxeDoCarnaval->id)['equivalentTo']);

        $this->assertSame([200, true], $this->post('curationLegitimate', ['id' => $afoxeDoCarnaval->id]));
        $this->assertSame([[], true], [$node($afoxe->id)['duplicates'], $node($afoxe->id)['legitimate']], 'Repetição legítima vale para o grupo todo');
        $this->assertSame([200, true], $this->post('curationLegitimateUndo', ['id' => $afoxe->id]));
        $this->assertSame([[$afoxeDoCarnaval->id], false], [$node($afoxe->id)['duplicates'], $node($afoxe->id)['legitimate']]);
    }

    function testExportsOnlyTheActiveTreeAsADownload()
    {
        $this->asAdmin();
        [, $afoxe] = $this->tree();
        $this->post('curationDisable', ['id' => $afoxe->id]);

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'curationExport'), false);
        $response = $this->app->response;
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attachment; filename="atuacao.json"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame(TreeExport::json(), $body);
        $this->assertStringNotContainsString('"Afoxé",', $body, 'O desativado não vai para a exportação');
        $this->assertStringContainsString('Romaria', $body);
    }

    function testHistoryRouteTellsWhoChangedWhat()
    {
        $this->asAdmin();
        [, , $romaria] = $this->tree();
        $this->post('curationRename', ['id' => $romaria->id, 'name' => 'Romarias']);
        $this->post('curationDisable', ['id' => $romaria->id]);

        [$status, $history] = $this->get('curationHistory', ['id' => $romaria->id]);

        $this->assertSame(200, $status);
        $this->assertSame([['desativou'], ['renomeou de "Romaria" para "Romarias"'], ['criou o item']], array_column($history, 'changes'), 'Do mais recente ao mais antigo');
        $this->assertSame($this->app->user->profile->name, $history[0]['who']);
    }
}
