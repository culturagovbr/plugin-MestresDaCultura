<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Services\ActingField;
use MDC\Services\FixedOpportunity;
use MDC\Services\Moderation;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// Na correção do cadastro devolvido, só os campos reabertos são cobrados.
class CorrectionValidationTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use RegistrationDirector;
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $this->captureLog();
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        return $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('reaberto', 'text', title: 'Documento', required: true)
                ->createField('novo', 'text', title: 'Criado depois do envio', required: true)
                ->createField('nova_lista', 'select', title: 'Lista criada depois do envio', required: true, options: ['Sim', 'Não'])
                ->createField('atuacao', ActingField::SLUG, title: 'Atuação Cultural')
                ->done()
            ->save()
            ->refresh()
            ->getInstance();
    }

    private function field(string $identifier, ?Opportunity $opportunity = null): string
    {
        return $this->opportunityBuilder->getFieldName($identifier, $opportunity);
    }

    /** Enviado; com `$reopened`, devolvido com esses campos reabertos e a correção ainda não enviada. */
    private function registrationIn(Opportunity $opportunity, ?array $reopened, array $values = []): Registration
    {
        $registration = $this->registrationDirector->createDraftRegistrations($opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        $this->app->disableAccessControl();
        foreach ($values as $field => $value) {
            $registration->$field = $value;
        }
        $registration->status = Registration::STATUS_SENT;
        if ($reopened !== null) {
            $registration->editableFields = $reopened;
            $registration->editableUntil = Moderation::NO_DEADLINE;
        }
        $registration->save(true);
        $this->app->enableAccessControl();

        return $registration;
    }

    function testInTheCorrectionOnlyTheReopenedFieldsAreRequired()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $registration = $this->registrationIn($opportunity, [$this->field('reaberto')]);

        $errors = $registration->getValidationErrors();

        $this->assertArrayHasKey($this->field('reaberto'), $errors, 'O campo reaberto segue cobrado');
        $this->assertArrayNotHasKey($this->field('novo'), $errors, 'O obrigatório fora da correção não trava o reenvio');
        $this->assertArrayNotHasKey($this->field('nova_lista'), $errors, 'Nem a lista obrigatória, cobrada pelos metadados');
    }

    function testOutsideTheCorrectionEveryRequiredFieldCounts()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;

        $errors = $this->registrationIn($opportunity, null)->getValidationErrors();

        $this->assertArrayHasKey($this->field('novo'), $errors, 'Sem correção, vale a regra do core');
        $this->assertArrayHasKey($this->field('nova_lista'), $errors);
    }

    function testOtherOpportunitiesAreLeftToTheCore()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->createOpportunity()->id;

        $errors = $this->registrationIn($opportunity, [$this->field('reaberto', $opportunity)])->getValidationErrors();

        $this->assertArrayHasKey($this->field('novo', $opportunity), $errors);
    }

    // Os erros das validações do plugin no envio (a Atuação, o ID de premiado) também saem fora dos reabertos.
    function testInTheCorrectionAFieldErrorOutsideTheReopenedDoesNotBlockTheResend()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $acting = $this->field('atuacao');
        $invalid = ['nodes' => 'não é lista', 'others' => []];

        $outside = $this->registrationIn($opportunity, null, [$acting => $invalid])->getSendValidationErrors();
        $this->assertArrayHasKey($acting, $outside, 'Fora da correção, a validação do plugin vale');

        $returned = $this->registrationIn($opportunity, [$this->field('reaberto')], [$acting => $invalid])->getSendValidationErrors();
        $this->assertArrayNotHasKey($acting, $returned, 'Na correção, o campo não reaberto não trava o reenvio');
    }
}
