<?php

namespace MDC;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityForm;
use MDC\Services\FestivityPlace;
use MDC\Services\FestivitySeal;
use MDC\Services\FestivitySearch;
use MDC\Services\FixedOpportunity;
use MDC\Services\Curators;
use MDC\Services\OtherQueue;
use MDC\Services\ProgramSeal;
use MDC\Services\ProgramVocabulary;
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

        // Fora do ar, a tela de aviso (views/mdc/unavailable.php); o motivo vai para o log.
        if (!$opportunity) {
            $this->render('unavailable');

            return;
        }

        if ($opportunity->status !== Opportunity::STATUS_ENABLED) {
            $app->log->error("MDC: a oportunidade {$opportunity->id} não está publicada; o cadastro não seria acessível a quem o criasse.");
            $this->render('unavailable');

            return;
        }

        // Busca e criação do cadastro em fila por usuário.
        $lock = "mdc-registration-{$app->user->id}";
        $app->lock($lock, wait_for_unlock: 5);

        try {
            if ($url = $this->existingUrl($opportunity)) {
                $app->redirect($url);
            }

            if (!$opportunity->canUser('register')) {
                $app->log->error("MDC: inscrição recusada na oportunidade {$opportunity->id}; o período está fechado.");
                $this->render('unavailable');

                return;
            }

            $registration = $this->create($opportunity);

            if (!$registration) {
                $this->render('unavailable');

                return;
            }

            $app->redirect($registration->editUrl);
        } finally {
            $app->unlock($lock);
        }
    }

    // Busca pública de Mestras e Mestres (views/mdc/masters.php), só no subsite dos Mestres; lista só quem tem o selo.
    function GET_masters()
    {
        $app = App::i();

        if (!Plugin::isTheme()) {
            $app->pass();
        }

        $app->view->jsObject['config']['mdcMasters'] = ['sealId' => (new ProgramSeal)->configuredId()];
        $this->render('masters');
    }

    // Busca pública de festejos (views/mdc/festivities.php), só no subsite dos Mestres; lista só os que têm o selo.
    function GET_festivities()
    {
        $app = App::i();

        if (!Plugin::isTheme()) {
            $app->pass();
        }

        $app->view->jsObject['config']['mdcFestivities'] = [
            'sealId' => (new FestivitySeal)->configuredId(),
            'classification' => FestivityFields::classification(),
            'sizes' => FestivityFields::definitions()[FestivityFields::SIZE]['options'],
            'monthParam' => FestivitySearch::MONTH_PARAM,
            'mastersParam' => FestivitySearch::MASTERS_PARAM,
            'formUrl' => $app->createUrl('mdc', 'festivity'),
        ];
        $this->render('festivities');
    }

    // "Meus locais" (views/mdc/places.php, `/meus-locais/`), no painel: os locais da agenda dos festejos da conta, com
    // edição de nome e endereço e exclusão dos que nenhum festejo usa.
    function GET_places()
    {
        $app = App::i();

        if (!Plugin::isTheme()) {
            $app->pass();
        }

        $this->requireAuthentication();

        $app->view->jsObject['config']['mdcPlaces'] = [
            'places' => FestivityPlace::ofUser($app->user),
            'formUrl' => $app->createUrl('mdc', 'festivity'),
        ];

        $this->layout = 'panel';
        $this->render('places');
    }

    // O cadastro do festejo em 6 etapas (views/mdc/festivity.php): `/cadastrar-festejo/` começa um e
    // `/cadastrar-festejo/{id}/` continua. Quem tem conta cadastra; continua quem edita o evento.
    function GET_festivity()
    {
        $app = App::i();

        if (!Plugin::isTheme()) {
            $app->pass();
        }

        $this->requireAuthentication();

        // O festejo pela API, com as imagens, os anexos, os vídeos e os links.
        if ($this->festivity = $this->requestedFestivity()) {
            $app->view->addRequestedEntityToJs(Event::class, $this->festivity->id);
        }

        ProgramVocabulary::apply(['Adicionar imagem de perfil' => i::__('Adicionar foto do festejo')]);

        $app->view->jsObject['config']['mdcFestivity'] = [
            'steps' => FestivityForm::steps(),
            'required' => FestivityForm::requiredKeys(),
            'classification' => FestivityFields::classification(),
            'classificationKeys' => ['segment' => FestivityFields::SEGMENT, 'typologies' => FestivityFields::TYPOLOGIES, 'other' => FestivityFields::OTHER_TYPOLOGY],
            'lists' => FestivityForm::lists(),
            'funding' => FestivityForm::funding(),
            'producers' => ['institution' => FestivityFields::WITH_INSTITUTION, 'cnpj' => FestivityFields::WITH_CNPJ],
            'dependencies' => FestivityFields::dependencies(),
            'person' => FestivityForm::person($this->festivity),
            // Só mestras e mestres da Rede entram no festejo (FestivityForm::invalid).
            'sealId' => (new ProgramSeal)->configuredId(),
            'formUrl' => $app->createUrl('mdc', 'festivity'),
            'checkUrl' => $app->createUrl('mdc', 'festivityCheck'),
        ];
        $this->render('festivity');
    }

    // Confere uma etapa do festejo, depois de salvar: os campos que faltam, campo => mensagens.
    function GET_festivityCheck()
    {
        $this->requireAuthentication();

        $event = $this->requestedFestivity() ?? App::i()->pass();
        $step = isset($this->data['step']) ? (int) $this->data['step'] : null;

        $this->json((object) FestivityForm::missing($event, $step));
    }

    // Festejo do cadastro, entregue ao tema como a entidade da página (Theme::addRequestedEntityToJs).
    private ?Event $festivity = null;

    function getRequestedEntity(): ?Event
    {
        return $this->festivity;
    }

    private function requestedFestivity(): ?Event
    {
        $app = App::i();

        if (!($id = $this->data['id'] ?? null)) {
            return null;
        }

        $event = $app->repo('Event')->find($id) ?? $app->pass();

        if (!Plugin::isFestivity($event)) {
            $app->pass();
        }

        $event->checkPermission('modify');

        return $event;
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

    // Libera a curadoria só no subsite dos Mestres e a quem Curators inclui; visitante vai para a autenticação.
    private function requireCurator(): void
    {
        if (!Plugin::isTheme()) {
            App::i()->pass();
        }

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

    // O endereço de edição do primeiro cadastro do usuário; mais de um vai para o log.
    private function existingUrl(Opportunity $opportunity): ?string
    {
        $app = App::i();
        $found = (new FixedOpportunity)->registrationsOf($app->user);

        if (!$found) {
            return null;
        }

        if (count($found) > 1) {
            $ids = implode(', ', array_map(fn (Registration $registration) => $registration->id, $found));

            $app->log->error("MDC: o usuário {$app->user->id} tem mais de um cadastro na oportunidade {$opportunity->id}: {$ids}.");
        }

        return $found[0]->editUrl;
    }

    // Grava o cadastro que passa nas validações da entidade; a recusa vai para o log.
    private function create(Opportunity $opportunity): ?Registration
    {
        $app = App::i();

        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $app->user->profile;

        if ($errors = $registration->validationErrors) {
            $app->log->error("MDC: cadastro recusado na oportunidade {$opportunity->id}: " . json_encode($errors, JSON_UNESCAPED_UNICODE));

            return null;
        }

        $registration->save(true);

        return $registration;
    }
}
