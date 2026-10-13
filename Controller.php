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
use MDC\Services\TreeCuration;
use MDC\Services\TreeExport;

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
        $this->requireAuthentication();

        if (!App::i()->user->is('admin')) {
            $this->errorJson(i::__('Só administradores fazem a curadoria da árvore.'), 403);
        }

        $this->json(App::i()->repo(Node::class)->findCurationTree());
    }

    // Baixa a árvore ativa no formato de data/atuacao.json (ver TreeExport).
    function GET_curationExport()
    {
        $this->requireAuthentication();

        $app = App::i();

        if (!$app->user->is('admin')) {
            $this->errorJson(i::__('Só administradores fazem a curadoria da árvore.'), 403);
        }

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

    // Executa a ação de curadoria; regra quebrada volta como a mensagem do serviço.
    private function curate(callable $action): void
    {
        $this->requireAuthentication();

        try {
            $result = $action(new TreeCuration());
        } catch (CurationRefused $e) {
            $this->errorJson($e->getMessage(), 400);
        } catch (PermissionDenied) {
            $this->errorJson(i::__('Só administradores fazem a curadoria da árvore.'), 403);
        }

        $this->json(is_int($result) ? ['id' => $result] : true);
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
