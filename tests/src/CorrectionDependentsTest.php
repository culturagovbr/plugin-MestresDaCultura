<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFileConfiguration;
use MDC\Services\Moderation;
use MDC\Services\PrizeId;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Na devolução, o campo recusado reabre com os que dependem dele, em cascata, e o ID do painel com o "não localizei".
class CorrectionDependentsTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private Opportunity $opportunity;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        $this->opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('Dados')
                ->createField('premiado', 'select', title: 'Já foi premiado?', options: ['Sim', 'Não'])
                ->createField('edital', 'checkboxes', title: 'Em qual edital?', field_condition: 'premiado:Sim', options: ['A', 'B'])
                ->createField('id', 'text', title: 'Informe o seu ID', field_condition: 'premiado:Sim', config: ['mdcRole' => PrizeId::ROLE_ID])
                ->createField('semId', 'checkbox', title: 'Não localizei o meu ID', field_condition: 'premiado:Sim', config: ['mdcRole' => PrizeId::ROLE_NOT_FOUND])
                ->createField('festejo', 'select', title: 'Realiza festejo?', options: ['Sim', 'Não'])
                ->createField('calendario', 'select', title: 'Está no Calendário?', field_condition: 'festejo:Sim', options: ['Sim', 'Não'])
                ->createField('qual', 'text', title: 'Qual festejo do Calendário?', field_condition: 'calendario:Sim')
                ->createField('reconhecido', 'select', title: 'É reconhecido?', options: ['Sim', 'Não'])
                ->createField('nome', 'text', title: 'Nome')
                ->done()
            ->save()
            ->getInstance();

        $file = new RegistrationFileConfiguration;
        $file->owner = $this->opportunity;
        $file->step = $this->opportunity->registrationSteps->first();
        $file->title = 'Comprovante do reconhecimento';
        $file->conditional = true;
        $file->conditionalField = $this->field('reconhecido');
        $file->conditionalValue = 'Sim';
        $file->save(true);

        $this->app->em->refresh($this->opportunity);
    }

    private function field(string $identifier): string
    {
        return $this->opportunityBuilder->getFieldName($identifier);
    }

    private function reopened(array $refused): array
    {
        $names = Moderation::fieldsToReopen($this->opportunity, $refused);
        sort($names);

        return $names;
    }

    private function names(string ...$identifiers): array
    {
        $names = array_map(fn ($identifier) => $this->field($identifier), $identifiers);
        sort($names);

        return $names;
    }

    function testTheRefusedQuestionReopensWithItsDependents()
    {
        $this->assertSame($this->names('premiado', 'edital', 'id', 'semId'), $this->reopened([$this->field('premiado')]));
    }

    function testTheDependentsReopenInCascade()
    {
        $this->assertSame($this->names('festejo', 'calendario', 'qual'), $this->reopened([$this->field('festejo')]));
    }

    function testTheConditionalAttachmentReopensWithItsQuestion()
    {
        $attachment = current((array) $this->opportunity->registrationFileConfigurations)->fileGroupName;
        $expected = [$this->field('reconhecido'), $attachment];
        sort($expected);

        $this->assertSame($expected, $this->reopened([$this->field('reconhecido')]));
    }

    function testThePanelIdReopensWithTheNotFoundMark()
    {
        $this->assertSame($this->names('id', 'semId'), $this->reopened([$this->field('id')]));
        $this->assertSame($this->names('id', 'semId'), $this->reopened([$this->field('semId')]));
    }

    function testAFieldWithoutDependentsReopensAlone()
    {
        $this->assertSame($this->names('nome'), $this->reopened([$this->field('nome')]));
        $this->assertSame($this->names('qual'), $this->reopened([$this->field('qual')]), 'O dependente recusado não reabre a pergunta de cima');
    }
}
