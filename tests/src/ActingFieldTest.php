<?php

namespace Tests\MDC;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Dtos\TreeNode;
use MDC\Entities\Node;
use MDC\Services\ActingField;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\AgentDirector;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// A árvore usada aqui é a da carga, que já está no banco de testes.
class ActingFieldTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        AgentDirector,
        UserDirector;

    private const PATH = ['Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas'];

    /** @return int[] ids da raiz até o último nome do caminho */
    private function ids(string ...$path): array
    {
        $ids = [];
        $nodes = $this->app->repo(Node::class)->findTree();

        foreach ($path as $name) {
            $node = current(array_filter($nodes, fn (TreeNode $n) => $n->name === $name));
            $this->assertNotFalse($node, "Nó não encontrado: {$name}");

            $ids[] = $node->id;
            $nodes = $node->children;
        }

        return $ids;
    }

    private function bruacas(): array
    {
        return $this->ids(...self::PATH);
    }

    /** Espelho do caminho, com os nomes informados (os da árvore, se omitidos). */
    private function snapshotOf(array $ids, array $names = self::PATH): array
    {
        $nodes = [];

        foreach ($ids as $i => $id) {
            $nodes[] = ['id' => $id, 'parent' => $i ? $ids[$i - 1] : null, 'name' => $names[$i]];
        }

        return $nodes;
    }

    private function curation(string $sql, array $params): void
    {
        $this->app->em->getConnection()->executeStatement($sql, $params);
    }

    function testRegistersTheFieldType()
    {
        $this->assertArrayHasKey(ActingField::SLUG, App::i()->getRegisteredRegistrationFieldTypes());
    }

    function testStoresNothingWhenNothingWasChosen()
    {
        $this->assertNull(ActingField::serialize(null));
        $this->assertNull(ActingField::serialize(['nodes' => [], 'others' => []]));
        $this->assertNull(ActingField::serialize(['nodes' => [], 'others' => [['parent' => null, 'text' => '   ']]]));
    }

    function testTakesNamesAndParentsFromTheTreeAndNotFromTheBrowser()
    {
        $ids = $this->bruacas();
        $forged = array_map(fn ($id) => ['id' => (string) $id, 'parent' => 999, 'name' => 'Forjado'], $ids);

        $stored = ActingField::unserialize(ActingField::serialize(['nodes' => $forged, 'others' => []]));

        $this->assertSame(['nodes' => $this->snapshotOf($ids), 'others' => []], $stored);
    }

    function testAcceptsPlainIdsAndTidiesTheOthers()
    {
        $ids = $this->bruacas();

        $stored = ActingField::unserialize(ActingField::serialize((object) [
            'nodes' => [...$ids, $ids[0]],
            'others' => [(object) ['parent' => (string) $ids[2], 'text' => '  Couro de bode  '], ['parent' => $ids[2], 'text' => '']],
        ]));

        $this->assertSame(['nodes' => $this->snapshotOf($ids), 'others' => [['parent' => $ids[2], 'text' => 'Couro de bode']]], $stored);
    }

    function testKeepsANodeDisabledByTheCurationSoNothingDisappearsUnnoticed()
    {
        $ids = $this->bruacas();
        $this->curation('UPDATE mdc_tree_node SET status = ? WHERE id = ?', [Node::STATUS_TRASH, $ids[2]]);

        $stored = ActingField::unserialize(ActingField::serialize(['nodes' => $ids, 'others' => []]));

        $this->assertSame($this->snapshotOf($ids), $stored['nodes']);
    }

    function testMarksAnIdThatDoesNotExistAsInvalid()
    {
        $stored = ActingField::unserialize(ActingField::serialize(['nodes' => [999999999], 'others' => []]));

        $this->assertFalse(ActingField::isValid($stored));
    }

    function testReadsNothingFromAnInvalidStoredValue()
    {
        $this->assertNull(ActingField::unserialize(null));
        $this->assertNull(ActingField::unserialize('não é json'));
    }

    function testAcceptsAPathWithAllItsAncestors()
    {
        $this->assertTrue(ActingField::isValid(['nodes' => $this->snapshotOf($this->bruacas()), 'others' => []]));
    }

    function testRefusesANodeWithoutItsParent()
    {
        $nodes = $this->snapshotOf($this->bruacas());
        unset($nodes[1]);

        $this->assertFalse(ActingField::isValid(['nodes' => array_values($nodes), 'others' => []]));
    }

    function testRefusesEntriesWithoutNameOrWithIdsThatAreNotIntegers()
    {
        $this->assertFalse(ActingField::isValid(['nodes' => [['id' => 1, 'parent' => null, 'name' => null]], 'others' => []]));
        $this->assertFalse(ActingField::isValid(['nodes' => [['id' => '1', 'parent' => null, 'name' => 'x']], 'others' => []]));
        $this->assertFalse(ActingField::isValid(['nodes' => [1], 'others' => []]));
        $this->assertFalse(ActingField::isValid(['nodes' => [], 'others' => [['parent' => [1], 'text' => 'x']]]));
    }

    function testRefusesARepeatedNode()
    {
        $node = ['id' => 1, 'parent' => null, 'name' => 'Saberes e Ofícios'];

        $this->assertFalse(ActingField::isValid(['nodes' => [$node, $node], 'others' => []]));
    }

    function testAcceptsTheGeneralOtherWithoutAnyNode()
    {
        $this->assertTrue(ActingField::isValid(['nodes' => [], 'others' => [['parent' => null, 'text' => 'Ofício que não está na lista']]]));
    }

    function testRefusesAnOtherInsideANodeThatWasNotChosen()
    {
        $ids = $this->bruacas();

        $value = ['nodes' => $this->snapshotOf([$ids[0]]), 'others' => [['parent' => $ids[2], 'text' => 'Couro de bode']]];

        $this->assertFalse(ActingField::isValid($value));
    }

    function testRefusesAnOtherLongerThanTheLimit()
    {
        $text = str_repeat('a', ActingField::OTHER_MAX_LENGTH + 1);

        $this->assertFalse(ActingField::isValid(['nodes' => [], 'others' => [['parent' => null, 'text' => $text]]]));
    }

    function testPointsOutADisabledNodeWithWhatIsBelowItAndTheOthersInside()
    {
        $ids = $this->bruacas();
        $this->curation('UPDATE mdc_tree_node SET status = ? WHERE id = ?', [Node::STATUS_TRASH, $ids[2]]);

        $value = ['nodes' => $this->snapshotOf($ids), 'others' => [['parent' => $ids[2], 'text' => 'Couro de bode']]];

        $this->assertSame(['Couro', 'Bruacas', 'Outro: Couro de bode'], ActingField::removedFromTree($value));
    }

    function testPointsOutNothingWhenTheTreeIsIntact()
    {
        $this->assertSame([], ActingField::removedFromTree(['nodes' => $this->snapshotOf($this->bruacas()), 'others' => []]));
    }

    function testShowsTheAnswerInOneCardPerSegmentBuiltOnlyFromTheSnapshot()
    {
        $value = [
            'nodes' => $this->snapshotOf([1, 4, 9, 10], ['Saberes e Ofícios', 'Artesanato', 'Couro', 'Biscoito']),
            'others' => [['parent' => 9, 'text' => 'Couro de bode'], ['parent' => null, 'text' => 'Benzedura']],
        ];

        $this->assertSame(
            '<div class="mdc-acting-view">'
            . '<div class="mdc-acting-view__segment"><div class="mdc-acting-view__title">Saberes e Ofícios</div>'
            . '<ul class="mdc-acting-view__tree"><li>Artesanato<ul><li>Couro<ul>'
            . '<li>Biscoito</li><li class="mdc-acting-view__other">Outro: Couro de bode</li>'
            . '</ul></li></ul></li></ul></div>'
            . '<p class="mdc-acting-view__other">Outro (geral): Benzedura</p>'
            . '</div>',
            ActingField::toHtml($value)
        );
    }

    function testShowsASegmentChosenAloneAsACardWithItsTitleOnly()
    {
        $value = ['nodes' => [['id' => 1, 'parent' => null, 'name' => 'Saberes e Ofícios']], 'others' => []];

        $this->assertSame(
            '<div class="mdc-acting-view"><div class="mdc-acting-view__segment"><div class="mdc-acting-view__title">Saberes e Ofícios</div></div></div>',
            ActingField::toHtml($value)
        );
    }

    function testEscapesNamesAndTextsInTheCards()
    {
        $value = ['nodes' => [['id' => 1, 'parent' => null, 'name' => '<b>Saberes</b>']], 'others' => [['parent' => 1, 'text' => '"x" & <script>']]];

        $this->assertSame(
            '<div class="mdc-acting-view"><div class="mdc-acting-view__segment"><div class="mdc-acting-view__title">&lt;b&gt;Saberes&lt;/b&gt;</div>'
            . '<ul class="mdc-acting-view__tree"><li class="mdc-acting-view__other">Outro: &quot;x&quot; &amp; &lt;script&gt;</li></ul></div></div>',
            ActingField::toHtml($value)
        );
    }

    function testWritesTheAnswerAsOnePathPerLineForThePdf()
    {
        $value = [
            'nodes' => [
                ...$this->snapshotOf([1, 4, 9, 10], ['Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas']),
                ['id' => 152, 'parent' => null, 'name' => 'Expressões e Manifestações'],
            ],
            'others' => [['parent' => 9, 'text' => 'Couro de bode'], ['parent' => null, 'text' => 'Benzedura']],
        ];

        $this->assertSame(
            "Saberes e Ofícios › Artesanato › Couro › Bruacas\n"
            . "Saberes e Ofícios › Artesanato › Couro › Outro: Couro de bode\n"
            . "Expressões e Manifestações\n"
            . "Outro (geral): Benzedura",
            ActingField::toText($value)
        );
    }

    function testReadsAsTextOnlyWhatWasStored()
    {
        $this->assertNull(ActingField::unserializeAsText(null));
        $this->assertSame('Saberes e Ofícios', ActingField::unserializeAsText('{"nodes":[{"id":1,"parent":null,"name":"Saberes e Ofícios"}],"others":[]}'));
    }

    function testShowsNothingForAnEmptyAnswer()
    {
        $this->assertSame('', ActingField::toHtml(['nodes' => [], 'others' => []]));
    }

    private function registrationWithField(bool $required = true): array
    {
        // No tema, só o super administrador do SaaS cria oportunidade.
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        /** @var Opportunity $opportunity */
        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('atuacao', ActingField::SLUG, title: 'Atuação Cultural', required: $required)
                ->done()
            ->save()
            ->refresh()
            ->getInstance();

        $field = $this->opportunityBuilder->getFieldName('atuacao');
        $registration = $this->registrationDirector->createDraftRegistrations($opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return [$registration, $field];
    }

    private function answer(Registration $registration, string $field, array $value): Registration
    {
        App::i()->controller('registration')->setEntityProperties($registration, [$field => $value]);
        $registration->save(true);

        return $this->reload($registration);
    }

    private function reload(Registration $registration): Registration
    {
        $registration = App::i()->repo('Registration')->find($registration->id);
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    function testRequiredFieldLeftEmptyBlocksTheSending()
    {
        [$registration, $field] = $this->registrationWithField();

        $registration = $this->answer($registration, $field, ['nodes' => [], 'others' => []]);

        $this->assertArrayHasKey($field, $registration->getSendValidationErrors());
    }

    function testInvalidSelectionBlocksTheSending()
    {
        [$registration, $field] = $this->registrationWithField(required: false);
        [$segmento, , , $bruacas] = $this->bruacas();

        App::i()->controller('registration')->setEntityProperties($registration, [$field => ['nodes' => [$segmento, $bruacas], 'others' => []]]);

        $this->assertContains(ActingField::invalidMessage(), $registration->getSendValidationErrors()[$field] ?? []);
    }

    function testValidSelectionIsStoredAsASnapshotAndPassesTheSending()
    {
        [$registration, $field] = $this->registrationWithField();
        $ids = $this->bruacas();

        $registration = $this->answer($registration, $field, ['nodes' => $ids, 'others' => []]);

        $this->assertSame(['nodes' => $this->snapshotOf($ids), 'others' => []], $registration->$field);
        $this->assertArrayNotHasKey($field, $registration->getSendValidationErrors());
    }

    function testDraftFollowsARenameInTheTree()
    {
        [$registration, $field] = $this->registrationWithField();
        $ids = $this->bruacas();
        $registration = $this->answer($registration, $field, ['nodes' => $ids, 'others' => []]);

        // Ao regravar, o nome de cada item vem do banco.
        $this->curation('UPDATE mdc_tree_node SET name = ? WHERE id = ?', ['Bruacas de couro', $ids[3]]);
        $nodes = $this->answer($registration, $field, ['nodes' => $ids, 'others' => []])->{$field}['nodes'];

        $this->assertSame('Bruacas de couro', end($nodes)['name']);
    }

    function testDraftWithANodeRemovedFromTheListCannotBeSent()
    {
        [$registration, $field] = $this->registrationWithField();
        $ids = $this->bruacas();
        $registration = $this->answer($registration, $field, ['nodes' => $ids, 'others' => []]);

        $this->curation('UPDATE mdc_tree_node SET status = ? WHERE id = ?', [Node::STATUS_TRASH, $ids[2]]);

        $errors = $this->reload($registration)->getSendValidationErrors()[$field] ?? [];

        $this->assertContains(ActingField::staleMessage(['Couro', 'Bruacas']), $errors);
    }

    function testSendingFreezesTheAnswer()
    {
        [$registration, $field] = $this->registrationWithField();
        $ids = $this->bruacas();
        $registration = $this->answer($registration, $field, ['nodes' => $ids, 'others' => [['parent' => $ids[2], 'text' => 'Couro de bode']]]);

        // Renomeado entre a última gravação e o envio: vale o nome do momento do envio.
        $this->curation('UPDATE mdc_tree_node SET name = ? WHERE id = ?', ['Bruacas de couro', $ids[3]]);
        $registration->send();
        $sent = $this->reload($registration)->$field;

        $this->assertSame('Bruacas de couro', end($sent['nodes'])['name']);

        // Depois do envio, a curadoria não altera a resposta, nem quando ela é gravada de novo.
        $this->curation('UPDATE mdc_tree_node SET name = ? WHERE id = ?', ['Biscoito de chocolate', $ids[3]]);
        $this->curation('UPDATE mdc_tree_node SET status = ? WHERE id = ?', [Node::STATUS_TRASH, $ids[2]]);

        $registration = $this->reload($registration);
        $this->assertSame($sent, $registration->$field);

        $registration = $this->answer($registration, $field, $registration->$field);
        $this->assertSame($sent, $registration->$field);
    }
}
