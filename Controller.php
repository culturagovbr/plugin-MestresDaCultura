<?php

namespace MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;
use MDC\Services\FixedOpportunity;
use MDC\Services\Curators;
use MDC\Services\OtherQueue;
use MDC\Services\TreeCuration;
use MDC\Services\TreeDuplicates;
use MDC\Services\TreeEquivalence;
use MDC\Services\TreeExport;
use MDC\Services\TreeHistory;

class Controller extends \MapasCulturais\Controller
{
    function GET_index()
    {
        $app = App::i();

        $this->requireAuthentication();

        $opportunity = (new FixedOpportunity)->get();

        if (!$opportunity) {
            $app->pass();
        }

        if ($opportunity->status !== Opportunity::STATUS_ENABLED) {
            $app->log->error("MDC: a oportunidade {$opportunity->id} não está publicada; o cadastro não seria acessível a quem o criasse.");

            $app->pass();
        }

        if ($url = $this->existingUrl($opportunity)) {
            $app->redirect($url);
        }

        if (!$opportunity->canUser('register')) {
            $app->log->error("MDC: inscrição recusada na oportunidade {$opportunity->id}; o período está fechado.");

            $app->pass();
        }

        $app->redirect($this->create($opportunity)->editUrl);
    }

    // Árvore pública, a mesma do cadastro.
    function GET_tree()
    {
        $this->json(App::i()->repo(Node::class)->findTree());
    }

    // Árvore da curadoria, com os desativados e o uso. Cada ação de curadoria responde `true` ou o id do item criado.
    function GET_curationTree()
    {
        $this->requireCurator();

        $this->json(App::i()->repo(Node::class)->findCurationTree());
    }

    // Histórico de um item, a partir das revisões do core (ver TreeHistory).
    function GET_curationHistory()
    {
        $this->requireCurator();

        $app = App::i();

        $node = $app->repo(Node::class)->find((int) ($this->data['id'] ?? 0));

        if (!$node) {
            $this->errorJson(i::__('Item da árvore não encontrado.'), 404);
        }

        $this->json((new TreeHistory())->forNode($node));
    }

    // Baixa a árvore ativa no formato de data/atuacao.json (ver TreeExport).
    function GET_curationExport()
    {
        $this->requireCurator();

        $app = App::i();

        $app->response = $app->response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="atuacao.json"');
        $app->halt(200, TreeExport::json());
    }

    function POST_curationAdd()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->add($this->nodeFromPost('parent', optional: true), (string) ($this->postData['name'] ?? ''))->id);
    }

    function POST_curationRename()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->rename($this->nodeFromPost('id'), (string) ($this->postData['name'] ?? '')));
    }

    function POST_curationReorder()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->reorder($this->nodeFromPost('id'), (int) ($this->postData['offset'] ?? 0) < 0 ? -1 : 1));
    }

    function POST_curationMove()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->move($this->nodeFromPost('id'), $this->nodeFromPost('parent', optional: true)));
    }

    function POST_curationDisable()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->disable($this->nodeFromPost('id')));
    }

    function POST_curationEnable()
    {
        $this->curate(fn (TreeCuration $curation) => $curation->enable($this->nodeFromPost('id')));
    }

    // Uma página da fila "Não encontrou?" (ver OtherQueue): `@limit`, `@page` e `@keyword` na consulta e os números,
    // contagens e totais no cabeçalho `API-Metadata`. `status`: pending (padrão) ou decided; `decision` filtra os tratados.
    function GET_curationOthers()
    {
        $this->requireCurator();

        $app = App::i();
        $limit = max(1, min(100, (int) ($this->data['@limit'] ?? 20)));
        $page = max(1, (int) ($this->data['@page'] ?? 1));
        $status = ($this->data['status'] ?? '') === OtherQueue::DECIDED ? OtherQueue::DECIDED : OtherQueue::PENDING;
        $decision = in_array($this->data['decision'] ?? null, OtherQueue::DECISIONS, true) ? $this->data['decision'] : null;
        $keyword = trim((string) ($this->data['@keyword'] ?? ''));

        $result = (new OtherQueue())->page($status, $keyword, $decision, $page, $limit);

        $app->response = $app->response->withHeader('API-Metadata', json_encode([
            'count' => $result['count'],
            'page' => $page,
            'limit' => $limit,
            'numPages' => (int) ceil($result['count'] / $limit),
            'keyword' => $keyword,
            'counts' => $result['counts'],
            'totals' => $result['totals'],
        ]));

        $this->json($result['entries']);
    }

    // `parent` é onde o texto foi escrito; `place`, onde o item novo entra (sem ele, vira segmento).
    function POST_curationOtherCreate()
    {
        $this->curate(fn () => (new OtherQueue())->create($this->nodeFromPost('parent', optional: true), (string) ($this->postData['key'] ?? ''), (string) ($this->postData['name'] ?? ''), $this->nodeFromPost('place', optional: true))->id);
    }

    function POST_curationOtherAssociate()
    {
        $this->curate(fn () => (new OtherQueue())->associate($this->nodeFromPost('parent', optional: true), (string) ($this->postData['key'] ?? ''), $this->nodeFromPost('node')));
    }

    function POST_curationOtherArchive()
    {
        $this->curate(fn () => (new OtherQueue())->archive($this->nodeFromPost('parent', optional: true), (string) ($this->postData['key'] ?? ''), (string) ($this->postData['note'] ?? '')));
    }

    function POST_curationLegitimate()
    {
        $this->curate(fn () => (new TreeDuplicates())->markLegitimate($this->nodeFromPost('id')));
    }

    function POST_curationLegitimateUndo()
    {
        $this->curate(fn () => (new TreeDuplicates())->unmarkLegitimate($this->nodeFromPost('id')));
    }

    function POST_curationEquivalence()
    {
        $this->curate(fn () => (new TreeEquivalence())->mark($this->nodeFromPost('id'), $this->nodeFromPost('to')));
    }

    function POST_curationEquivalenceUndo()
    {
        $this->curate(fn () => (new TreeEquivalence())->undo($this->nodeFromPost('id')));
    }

    // Executa a ação de curadoria; regra quebrada volta como a mensagem do serviço.
    private function curate(callable $action): void
    {
        // Barra quem não é curador antes de a ação mexer no item.
        $this->requireCurator();

        try {
            $result = $action(new TreeCuration());
        } catch (CurationRefused $e) {
            $this->errorJson($e->getMessage(), 400);
        } catch (PermissionDenied) {
            $this->errorJson(Curators::refusal(), 403);
        }

        $this->json(is_int($result) ? ['id' => $result] : true);
    }

    // Curadoria é só para quem Curators inclui; visitante vai para a autenticação.
    private function requireCurator(): void
    {
        $this->requireAuthentication();

        if (!Curators::includes()) {
            $this->errorJson(Curators::refusal(), 403);
        }
    }

    // Item da árvore do POST; sem o id e com `optional`, null (a raiz).
    private function nodeFromPost(string $key, bool $optional = false): ?Node
    {
        $id = $this->postData[$key] ?? null;

        if (($id === null || $id === '') && $optional) {
            return null;
        }

        $node = App::i()->repo(Node::class)->find((int) $id);

        if (!$node || $node->status === Node::STATUS_TRASH) {
            $this->errorJson(i::__('Item da árvore não encontrado.'), 404);
        }

        return $node;
    }

    // Sem `@permissions` a consulta filtraria por `view` (ApiQuery.php:3582), que o cache de
    // permissão não guarda para rascunho, e o dono não acharia a própria inscrição.
    private function existingUrl(Opportunity $opportunity): ?string
    {
        $found = (new ApiQuery(Registration::class, [
            '@select' => 'id,editUrl',
            '@permissions' => '@control',
            '@order' => 'id ASC',
            'opportunity' => "EQ({$opportunity->id})",
            'user' => 'EQ(@me)',
            'status' => 'GTE(0)',
        ]))->getFindResult();

        if (!$found) {
            return null;
        }

        if (count($found) > 1) {
            $ids = implode(', ', array_column($found, 'id'));

            App::i()->log->error("MDC: o usuário " . App::i()->user->id . " tem mais de um cadastro na oportunidade {$opportunity->id}: {$ids}.");
        }

        return reset($found)['editUrl'];
    }

    private function create(Opportunity $opportunity): Registration
    {
        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = App::i()->user->profile;
        $registration->save(true);

        return $registration;
    }
}
