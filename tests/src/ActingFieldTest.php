<?php

namespace Tests\MDC;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
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
        return $this->ids('Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas');
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

    function testTidiesTheValueBeforeStoring()
    {
        $stored = ActingField::serialize((object) [
            'nodes' => ['3', 3, 7],
            'others' => [
                (object) ['parent' => '7', 'text' => '  Couro de bode  '],
                (object) ['parent' => 7, 'text' => ''],
            ],
        ]);

        $this->assertSame(
            ['nodes' => [3, 7], 'others' => [['parent' => 7, 'text' => 'Couro de bode']]],
            ActingField::unserialize($stored)
        );
    }

    function testReadsNothingFromAnInvalidStoredValue()
    {
        $this->assertNull(ActingField::unserialize(null));
        $this->assertNull(ActingField::unserialize('não é json'));
    }

    function testAcceptsAPathWithAllItsAncestors()
    {
        $this->assertTrue(ActingField::isValid(['nodes' => $this->bruacas(), 'others' => []]));
    }

    function testRefusesANodeWithoutItsParent()
    {
        [$segmento, , $couro, $bruacas] = $this->bruacas();

        $this->assertFalse(ActingField::isValid(['nodes' => [$segmento, $couro, $bruacas], 'others' => []]));
    }

    function testRefusesANodeThatDoesNotExist()
    {
        $this->assertFalse(ActingField::isValid(['nodes' => [999999999], 'others' => []]));
    }

    function testRefusesANodeDisabledByTheCuration()
    {
        $ids = $this->bruacas();

        $this->app->em->getConnection()->executeStatement(
            'UPDATE mdc_tree_node SET status = ? WHERE id = ?',
            [Node::STATUS_TRASH, end($ids)]
        );

        $this->assertFalse(ActingField::isValid(['nodes' => $ids, 'others' => []]));
    }

    function testRefusesIdsThatAreNotIntegers()
    {
        $this->assertFalse(ActingField::isValid(['nodes' => ['1'], 'others' => []]));
        $this->assertFalse(ActingField::isValid(['nodes' => [], 'others' => [['parent' => [1], 'text' => 'x']]]));
    }

    function testAcceptsTheGeneralOtherWithoutAnyNode()
    {
        $this->assertTrue(ActingField::isValid(['nodes' => [], 'others' => [['parent' => null, 'text' => 'Ofício que não está na lista']]]));
    }

    function testAcceptsAnOtherInsideAChosenNode()
    {
        $ids = $this->ids('Saberes e Ofícios', 'Artesanato', 'Couro');

        $this->assertTrue(ActingField::isValid(['nodes' => $ids, 'others' => [['parent' => end($ids), 'text' => 'Couro de bode']]]));
    }

    function testRefusesAnOtherInsideANodeThatWasNotChosen()
    {
        [$segmento, , $couro] = $this->bruacas();

        $this->assertFalse(ActingField::isValid(['nodes' => [$segmento], 'others' => [['parent' => $couro, 'text' => 'Couro de bode']]]));
    }

    function testRefusesAnOtherLongerThanTheLimit()
    {
        $text = str_repeat('a', ActingField::OTHER_MAX_LENGTH + 1);

        $this->assertFalse(ActingField::isValid(['nodes' => [], 'others' => [['parent' => null, 'text' => $text]]]));
    }

    private function registrationWithField(bool $required): array
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

    private function reload($registration)
    {
        $registration = App::i()->repo('Registration')->find($registration->id);
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    function testRequiredFieldLeftEmptyBlocksTheSending()
    {
        [$registration, $field] = $this->registrationWithField(required: true);

        App::i()->controller('registration')->setEntityProperties($registration, [$field => ['nodes' => [], 'others' => []]]);
        $registration->save(true);

        $this->assertArrayHasKey($field, $this->reload($registration)->getSendValidationErrors());
    }

    function testInvalidSelectionBlocksTheSending()
    {
        [$registration, $field] = $this->registrationWithField(required: false);
        [$segmento, , , $bruacas] = $this->bruacas();

        App::i()->controller('registration')->setEntityProperties($registration, [$field => ['nodes' => [$segmento, $bruacas], 'others' => []]]);
        $registration->save(true);

        $this->assertArrayHasKey($field, $this->reload($registration)->getSendValidationErrors());
    }

    function testValidSelectionIsStoredAndPassesTheSending()
    {
        [$registration, $field] = $this->registrationWithField(required: true);
        $ids = $this->bruacas();

        App::i()->controller('registration')->setEntityProperties($registration, [$field => ['nodes' => $ids, 'others' => []]]);
        $registration->save(true);

        $registration = $this->reload($registration);

        $this->assertSame(['nodes' => $ids, 'others' => []], $registration->$field);
        $this->assertArrayNotHasKey($field, $registration->getSendValidationErrors());
    }
}
