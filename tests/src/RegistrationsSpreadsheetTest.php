<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Registration;
use MDC\Dtos\TreeNode;
use MDC\Entities\Node;
use MDC\Services\ActingField;
use MDC\Services\FixedOpportunity;
use MDC\Services\RegistrationsSpreadsheet;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// A planilha de inscrições, com o job montado e rodado pelos métodos públicos do core.
class RegistrationsSpreadsheetTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        UserDirector;

    /** Inscrição numa oportunidade nova, que passa a ser a do programa. */
    private function registration(): Registration
    {
        // No tema, só o super administrador do SaaS cria oportunidade.
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('secao', 'section', title: 'Contato')
                ->createField('nome', 'text', title: 'Nome')
                ->createField('aceite', 'checkbox', title: 'Aceito que meu telefone fique público')
                ->createField('lgpd', 'checkbox', title: 'Declaro que as informações são verdadeiras')
                ->createField('atuacao', ActingField::SLUG, title: 'Atuação Cultural')
                ->done()
            ->save()
            ->refresh()
            ->getInstance();

        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;

        $registration = $this->registrationDirector->createDraftRegistrations($opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    private function field(string $identifier): string
    {
        return $this->opportunityBuilder->getFieldName($identifier);
    }

    /** @return int[] ids da raiz até o último nome do caminho, na árvore da carga */
    private function path(string ...$names): array
    {
        $ids = [];
        $nodes = $this->app->repo(Node::class)->findTree();

        foreach ($names as $name) {
            $node = current(array_filter($nodes, fn (TreeNode $n) => $n->name === $name));
            $ids[] = $node->id;
            $nodes = $node->children;
        }

        return $ids;
    }

    private function job(Registration $registration, string $select): Job
    {
        return $this->app->enqueueOrReplaceJob(RegistrationsSpreadsheet::JOB, [
            'owner' => $registration->opportunity,
            'authenticatedUser' => $this->app->user,
            'extension' => 'csv',
            'entityClassName' => Registration::class,
            'query' => ['@select' => $select, '@opportunity' => $registration->opportunity->id],
        ]);
    }

    private function batch(Job $job): array
    {
        $job_type = $this->app->getRegisteredJobType(RegistrationsSpreadsheet::JOB);
        (new \ReflectionProperty($job_type, 'page'))->setValue($job_type, 1);

        return $job_type->getBatch($job);
    }

    /** @return array<string,string> coluna => título */
    private function columns(Job $job): array
    {
        return $this->app->getRegisteredJobType(RegistrationsSpreadsheet::JOB)->getHeader($job);
    }

    function testWritesTheActingAnswerAsPathsInOneCellAndRestoresTheReadingAfterTheBatch()
    {
        $registration = $this->registration();
        $field = $this->field('atuacao');
        $ids = $this->path('Saberes e Ofícios', 'Artesanato', 'Couro', 'Bruacas');

        $this->app->controller('registration')->setEntityProperties($registration, [
            $field => ['nodes' => $ids, 'others' => [['parent' => $ids[2], 'text' => 'Couro de bode']]],
        ]);
        $registration->save(true);
        // A gestão exporta as inscrições enviadas.
        $registration->send();

        $rows = array_column($this->batch($this->job($registration, "number,{$field}")), $field, 'number');

        $this->assertSame(
            'Saberes e Ofícios › Artesanato › Couro › Bruacas | Saberes e Ofícios › Artesanato › Couro › Outro: Couro de bode',
            $rows[$registration->number] ?? null,
            'O job apaga as quebras de linha da célula: os caminhos vão separados por " | "'
        );

        $this->assertSame(
            [ActingField::class, 'unserialize'],
            $this->app->getRegisteredMetadata(Registration::class)[$field]->unserialize,
            'Depois do lote, o campo volta a ser lido como espelho, para o resto do processo do job'
        );
    }

    /** Linha da inscrição no lote, depois de responder pelo caminho do formulário e enviar. */
    private function exportedRow(Registration $registration, array $answers, string $select): array
    {
        $values = [];

        foreach ($answers as $identifier => $value) {
            $values[$this->field($identifier)] = $value;
        }

        $this->app->controller('registration')->setEntityProperties($registration, $values);
        $registration->save(true);
        $registration->send();

        $rows = array_column($this->batch($this->job($registration, $select)), null, 'number');

        return $rows[$registration->number] ?? [];
    }

    function testWritesTheCheckboxesAsYesOrNoAndTheOwnerAsSent()
    {
        $registration = $this->registration();
        $owner = $registration->owner->name;
        $row = $this->exportedRow($registration, ['aceite' => false, 'lgpd' => true], 'number,owner.{name}');

        $this->assertSame('Não', $row[$this->field('aceite')] ?? null, 'A caixa desmarcada é a resposta "Não", e não uma célula em branco');
        $this->assertSame('Sim', $row[$this->field('lgpd')] ?? null);
        $this->assertSame($owner, $row['ownerName'] ?? null, 'Sem os dados dos agentes na consulta, o responsável sai em branco');
    }

    function testAsksForTheStatusSoTheAgentFieldsShowWhatWasSentOnlyInTheProgramOpportunity()
    {
        $other = $this->registration();
        $other_select = 'number,' . $this->field('nome');
        $program = $this->registration();
        $program_select = 'number,' . $this->field('nome');

        $other_job = $this->job($other, $other_select);
        $program_job = $this->job($program, $program_select);
        $this->batch($other_job);
        $this->batch($program_job);

        $this->assertSame($other_select, $other_job->query['@select'], 'Fora da oportunidade do programa, a consulta fica como veio');
        $this->assertSame("{$program_select},status,agentsData", $program_job->query['@select'], 'Sem o status, os campos do agente saem do perfil atual');
    }

    function testLeavesOutTheColumnsThatNeverHaveAnAnswerOnlyInTheProgramOpportunity()
    {
        $other = $this->registration();
        $other_section = $this->field('secao');
        $program = $this->registration();
        [$program_section, $program_name] = [$this->field('secao'), $this->field('nome')];

        // O core põe todos os campos do formulário no cabeçalho, escolhidos ou não.
        $other_columns = $this->columns($this->job($other, 'number'));
        $program_columns = $this->columns($this->job($program, 'number'));

        $this->assertArrayHasKey($other_section, $other_columns, 'Fora da oportunidade do programa, o cabeçalho fica como o core monta');
        $this->assertArrayNotHasKey($program_section, $program_columns, 'Seção não tem resposta: não vira coluna vazia');
        $this->assertArrayNotHasKey('projectName', $program_columns, 'O programa não usa projeto');
        $this->assertArrayHasKey($program_name, $program_columns);
    }

    function testCountsTheAgeInCompleteYearsOnTheExportDate()
    {
        $on = new \DateTimeImmutable('2026-10-23');

        $this->assertSame(68, RegistrationsSpreadsheet::age('1958-03-20', $on));
        $this->assertSame(67, RegistrationsSpreadsheet::age('1958-10-24', $on), 'O aniversário ainda não chegou');
        $this->assertSame(68, RegistrationsSpreadsheet::age('1958-10-23', $on), 'No dia do aniversário, já conta');
        $this->assertSame(68, RegistrationsSpreadsheet::age(new \DateTime('1958-03-20'), $on));
        $this->assertNull(RegistrationsSpreadsheet::age(null, $on));
        $this->assertNull(RegistrationsSpreadsheet::age('2030-01-01', $on), 'Data no futuro não vira idade negativa');
    }
}
