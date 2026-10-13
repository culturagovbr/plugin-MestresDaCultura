<?php

namespace Tests\MDC;

use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use MDC\Entities\Node;
use MDC\Services\ActingField;
use MDC\Services\OtherQueue;
use MDC\Services\TreeCuration;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * O que a curadoria lê das respostas de cadastro: quantos marcaram cada item e a fila "Não encontrou?".
 * Dois cadastros, um enviado e um rascunho; o envio é só o status, sem o fluxo de envio.
 */
class CurationUsageTest extends TestCase
{
    use OpportunityBuilder;
    use RegistrationDirector;
    use RequestFactory;
    use UserDirector;

    /** @var Node[] */
    private array $tree;

    /** @var Registration[] */
    private array $registrations;

    protected function setUp(): void
    {
        parent::setUp();

        // O super administrador do SaaS cria a oportunidade e faz a curadoria.
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->app->em->getConnection()->executeStatement('DELETE FROM mdc_tree_node');

        $curation = new TreeCuration();
        $saberes = $curation->add(null, 'Saberes e Ofícios');
        $artesanato = $curation->add($saberes, 'Artesanato');
        $this->tree = [$saberes, $artesanato, $curation->add($artesanato, 'Couro'), $curation->add($artesanato, 'Barro')];

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $this->app->user->profile, owner_entity: $this->app->user->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('atuacao', ActingField::SLUG, title: 'Atuação Cultural')
                ->done()
            ->save()
            ->refresh()
            ->getInstance();

        $this->registrations = $this->registrationDirector->createDraftRegistrations($opportunity, 2);
    }

    private function answer(Registration $registration, array $nodes, array $others): void
    {
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();
        App::i()->controller('registration')->setEntityProperties($registration, [$this->opportunityBuilder->getFieldName('atuacao') => ['nodes' => $nodes, 'others' => $others]]);
        $registration->save(true);
    }

    private function answerBoth(): void
    {
        [$saberes, $artesanato, $couro] = $this->tree;

        $this->answer($this->registrations[0], [$saberes->id, $artesanato->id, $couro->id], [['parent' => $artesanato->id, 'text' => 'Couro de bode']]);
        $this->answer($this->registrations[1], [$saberes->id, $artesanato->id], [['parent' => $artesanato->id, 'text' => ' couro  de  Bode'], ['parent' => null, 'text' => 'Benzedura']]);
        $this->app->em->getConnection()->executeStatement('UPDATE registration SET status = ? WHERE id = ?', [Registration::STATUS_SENT, $this->registrations[0]->id]);
    }

    private function call(ServerRequestInterface $request): array
    {
        $this->app->reset();
        $this->app->run($request, false);

        return [$this->app->response->getStatusCode(), json_decode((string) $this->app->response->getBody(), true)];
    }

    function testCountsSentAndDraftRegistrationsForEachItemAndItsParents()
    {
        $this->answerBoth();

        [$status, $tree] = $this->call($this->requestFactory->GET('mdc', 'curationTree', ajax: true));
        $artesanato = $tree[0]['children'][0];
        $usage = fn (array $node) => [$node['name'], $node['sent'], $node['drafts']];

        $this->assertSame(200, $status);
        $this->assertSame(['Saberes e Ofícios', 1, 1], $usage($tree[0]), 'O pai conta junto, porque o espelho o guarda');
        $this->assertSame(['Artesanato', 1, 1], $usage($artesanato));
        $this->assertSame([['Couro', 1, 0], ['Barro', 0, 0]], array_map($usage, $artesanato['children']));
    }

    function testGroupsTheOthersWrittenAndRecordsTheDecisionThroughTheRoute()
    {
        $this->answerBoth();
        [, $artesanato, $couro] = $this->tree;

        $entries = array_column(array_map(fn ($entry) => (array) $entry, (new OtherQueue())->entries()), null, 'key');

        $this->assertSame([$artesanato->id, 'Couro de bode', 1, 1, null], [$entries['couro de bode']['parent'], $entries['couro de bode']['text'], $entries['couro de bode']['sent'], $entries['couro de bode']['drafts'], $entries['couro de bode']['decision']], 'O mesmo texto, escrito de jeitos diferentes, é um grupo só');
        $this->assertSame([null, 0, 1], [$entries['benzedura']['parent'], $entries['benzedura']['sent'], $entries['benzedura']['drafts']]);

        $associate = fn () => $this->call($this->requestFactory->POST('mdc', 'curationOtherAssociate', payload: ['parent' => $artesanato->id, 'key' => 'couro de bode', 'node' => $couro->id]));

        $this->assertSame([200, true], $associate());
        $this->assertSame([400, ['error' => true, 'data' => 'Esse texto já foi tratado.']], $associate(), 'Cada texto recebe uma decisão só');

        [$status, $queue] = $this->call($this->requestFactory->GET('mdc', 'curationOthers', ajax: true));
        $decided = array_column($queue, null, 'key')['couro de bode'];

        $this->assertSame(200, $status);
        $this->assertSame(['associated', $couro->id], [$decided['decision']['type'], $decided['decision']['node']]);
        $this->assertSame('benzedura', $queue[0]['key'], 'Os textos ainda sem decisão vêm primeiro');
    }
}
