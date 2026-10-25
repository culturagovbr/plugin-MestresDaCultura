<?php

namespace Tests\MDC;

use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use MDC\Services\RegistrationsTable;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// Lista de cadastros do programa na gestão: a situação da moderação no lugar do resultado da avaliação documental, a
// última atualização à vista e sem as colunas que o programa não usa; as outras oportunidades não aparecem no subsite.
class RegistrationsTableTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private function createOpportunity(User $owner): Opportunity
    {
        return $this->opportunityBuilder
            ->reset(owner: $owner->profile, owner_entity: $owner->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    /** Cadastro enviado, com os campos reabertos e a correção enviada como pedido. */
    private function sentRegistration(Opportunity $opportunity, array $editable = [], ?string $resent = null): Registration
    {
        $this->app->disableAccessControl();
        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $this->userDirector->createUser()->profile;
        $registration->save(true);
        $this->app->conn->executeStatement('UPDATE registration SET status = 1, sent_timestamp = now() WHERE id = :id', ['id' => $registration->id]);
        $registration = $this->app->repo('Registration')->find($registration->id);
        $registration->editableFields = $editable ?: null;
        $registration->save(true);
        // Grava a data do reenvio direto no banco.
        $this->app->conn->executeStatement('UPDATE registration SET edit_sent_timestamp = :resent WHERE id = :id', ['resent' => $resent, 'id' => $registration->id]);
        $this->app->enableAccessControl();

        return $registration;
    }

    /** @return array<int, array> as linhas da lista, como a tela pede, pelo id */
    private function listRows(User $user, Opportunity $opportunity): array
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run(new ServerRequest(method: 'GET', uri: '/api/opportunity/findRegistrations', queryParams: [
            '@opportunity' => $opportunity->id,
            '@select' => 'id,number,status,editableFields,editSentTimestamp,updateTimestamp',
        ]), false);

        return array_column(json_decode((string) $this->app->response->getBody(), true) ?: [], null, 'id');
    }

    function testTheSituationOfEachModerationStep()
    {
        $this->assertSame('waiting', Moderation::situation(Registration::STATUS_SENT, null, null));
        $this->assertSame('returned', Moderation::situation(Registration::STATUS_SENT, ['rfc_41'], null));
        $this->assertSame('resent', Moderation::situation(Registration::STATUS_SENT, ['rfc_41'], '2026-10-20 10:00:00'));
        $this->assertSame('approved', Moderation::situation(Registration::STATUS_APPROVED, null, null));
        $this->assertSame('draft', Moderation::situation(Registration::STATUS_DRAFT, null, null));
    }

    function testTheProgramColumns()
    {
        $headers = [
            ['text' => 'inscrição', 'value' => 'number', 'slug' => 'number'],
            ['text' => 'agente', 'value' => 'owner?.name', 'slug' => 'agent'],
            ['text' => 'Resultado consolidado', 'value' => 'consolidatedResult', 'slug' => 'consolidatedResult'],
            ['text' => 'Avaliação', 'value' => 'consolidatedResult'],
            ['text' => 'Divisão geográfica do responsável – Estado', 'value' => 'owner?.geoEstado', 'slug' => 'owner?.geoEstado'],
            ['text' => 'Faixa/Linha', 'value' => 'range', 'slug' => 'range'],
            ['text' => 'Status das metas', 'value' => 'goalStatuses', 'slug' => 'goalStatuses'],
            ['text' => 'data de envio', 'value' => 'sentTimestamp', 'slug' => 'sentTimestamp'],
            ['text' => 'Data de Atualização', 'value' => 'updateTimestamp', 'slug' => 'updateTimestamp'],
            ['text' => 'Status', 'value' => 'status', 'slug' => 'status'],
            ['text' => 'Situação da moderação', 'value' => 'mdcModeration', 'slug' => 'mdcModeration'],
        ];
        $select = 'number,status,sentTimestamp';

        RegistrationsTable::adjust($headers, $select);

        $this->assertSame(
            ['inscrição', 'agente', 'Situação da moderação', 'data de envio', 'Última atualização', 'Status'],
            array_column($headers, 'text'),
            'Sai o resultado da avaliação e o que o programa não usa; entra a situação, depois do agente'
        );
        $this->assertContains('updateTimestamp', explode(',', $select), 'A última atualização vem na consulta');
    }

    function testTheListBringsTheModerationSituationOnlyForTheProgram()
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);
        $fixed = $this->createOpportunity($manager);
        $other = $this->createOpportunity($manager);
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;

        $waiting = $this->sentRegistration($fixed);
        $returned = $this->sentRegistration($fixed, ['rfc_41']);
        $resent = $this->sentRegistration($fixed, ['rfc_41'], '2026-10-20 10:00:00');
        $elsewhere = $this->sentRegistration($other, ['rfc_41']);

        $rows = $this->listRows($manager, $fixed);
        $this->assertSame('Aguardando moderação', $rows[$waiting->id][RegistrationsTable::MODERATION] ?? null);
        $this->assertSame('Devolvido ao mestre', $rows[$returned->id][RegistrationsTable::MODERATION] ?? null);
        $this->assertSame('Reenviado', $rows[$resent->id][RegistrationsTable::MODERATION] ?? null);

        // No subsite dos Mestres, nem o saasSuperAdmin vê as inscrições de outra oportunidade.
        $this->assertArrayNotHasKey($elsewhere->id, $this->listRows($manager, $other), 'Outra oportunidade não lista nada no subsite');
    }
}
