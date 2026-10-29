<?php

namespace Tests\MDC;

use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationStep;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A estrutura da oportunidade do programa (fases, datas, formulário) é só do saasSuperAdmin. Na comissão, o gestor
// segue incluindo e desabilitando moderadores, mas não exclui nem reabre.
class FixedOpportunityStructureTest extends TestCase
{
    use OpportunityBuilder;
    use OutsideTheSubsite;
    use RequestFactory;
    use UserDirector;

    private Opportunity $fixed;

    private EvaluationMethodConfigurationAgentRelation $moderator;

    private User $manager;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($this->superAdmin);

        $this->opportunityBuilder
            ->reset(owner: $this->superAdmin->profile, owner_entity: $this->superAdmin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save();

        $this->moderator = $this->opportunityBuilder->addEvaluationPhase(EvaluationMethods::documentary)
            ->setEvaluationPeriod(new ConcurrentEndingAfter)
            ->save()
            ->addValuer('Moderação', 'Moderadora')
            ->getInstance();

        $this->opportunityBuilder->addDataCollectionPhase()
            ->setRegistrationPeriod(new Open)
            ->save();

        $this->fixed = $this->opportunityBuilder->refresh()->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->fixed->id;
        $this->manager = $this->userDirector->createUser('admin');
    }

    private function phase(): Opportunity
    {
        return current(array_filter($this->fixed->allPhases, fn ($phase) => $phase->id !== $this->fixed->id));
    }

    private function statusOf(string $controller, string $action, array $url, array $payload = []): int
    {
        $this->login($this->manager);
        $this->app->reset();
        $this->app->run($this->requestFactory->POST($controller, $action, $url, $payload), false);

        return $this->app->response->getStatusCode();
    }

    /** PATCH como a tela faz, com Force-Save opcional. */
    private function patch(User $user, EvaluationMethodConfiguration|Opportunity $entity, array $changes, bool $force = false): int
    {
        $this->login($user);
        $this->app->reset();
        $headers = $force ? ['MAPAS-Force-Save' => '1'] : [];
        $this->app->run($this->requestFactory->PATCH($entity->controllerId, 'single', [$entity->id], $changes, headers: $headers), false);

        return $this->app->response->getStatusCode();
    }

    /** A fase de recurso é filha da fase que ela recorre, e não da raiz. */
    private function appealPhase(): Opportunity
    {
        $this->login($this->superAdmin);
        $phase = $this->phase();
        $class = $phase->specializedClassName;

        $appeal = new $class;
        $appeal->parent = $phase;
        $appeal->status = Opportunity::STATUS_APPEAL_PHASE;
        $appeal->name = 'Recurso';
        $appeal->ownerEntity = $phase->ownerEntity;
        $appeal->registrationFrom = $phase->registrationFrom;
        $appeal->registrationTo = $phase->registrationTo;
        $appeal->isAppealPhase = true;
        $appeal->save(true);

        return $appeal;
    }

    function testCreatingOrRemovingAPhaseIsRefusedToTheManager()
    {
        $this->login($this->manager);
        $this->assertException(PermissionDenied::class, fn () => $this->opportunityBuilder->addDataCollectionPhase(), 'O gestor não cria fase');

        $evaluation = new EvaluationMethodConfiguration;
        $evaluation->opportunity = $this->phase();
        $evaluation->type = EvaluationMethods::simple->name;
        $this->assertException(PermissionDenied::class, fn () => $evaluation->save(true), 'Nem fase de avaliação');

        $this->assertFalse($this->phase()->canUser('remove', $this->manager), 'Nem exclui fase');

        // Fora do subsite dos Mestres, a trava do programa recusa a fase.
        $this->outsideTheSubsite(function () {
            $phase = new ($this->fixed->specializedClassName);
            $phase->parent = $this->fixed;
            $this->assertFalse($phase->canUser('create', $this->manager), 'Nem fora do subsite dos Mestres');
        });

        $this->login($this->superAdmin);
        $phases = count($this->fixed->allPhases);
        $this->opportunityBuilder->addDataCollectionPhase();
        $this->assertCount($phases + 1, $this->fixed->refreshed()->allPhases, 'O saasSuperAdmin segue criando fase');
    }

    /** @return string[] as datas recusadas por serem do programa; a fase de teste tem também erros de data do core */
    private function lockedDates(EvaluationMethodConfiguration|Opportunity $entity): array
    {
        $locked = array_filter($entity->validationErrors, fn ($errors) => (bool) preg_grep('/administração da plataforma/', (array) $errors));

        return array_keys($locked);
    }

    function testChangingTheDatesIsRefusedToTheManager()
    {
        $later = fn (?\DateTime $date) => (clone ($date ?? new \DateTime))->modify('+1 day');
        $changes = [
            [$this->fixed, 'registrationTo'],
            [$this->phase(), 'registrationFrom'],
            [$this->fixed, 'publishTimestamp'],
            [$this->fixed->evaluationMethodConfiguration, 'evaluationTo'],
        ];

        $this->login($this->manager);
        foreach ($changes as [$entity, $property]) {
            $original = $entity->$property;
            $entity->$property = $later($original);
            $this->assertContains($property, $this->lockedDates($entity), "O gestor não muda {$property}");

            $entity->$property = $original?->format('Y-m-d H:i');
            $this->assertNotContains($property, $this->lockedDates($entity), "Reenviar {$property} sem mudar segue valendo");
            $entity->$property = $later($original);
        }

        $this->login($this->superAdmin);
        foreach ($changes as [$entity, $property]) {
            $this->assertSame([], $this->lockedDates($entity), "O saasSuperAdmin segue mudando {$property}");
        }
    }

    // O gestor não muda a data final nem o fluxo contínuo.
    function testTheContinuousFlowIsLockedToTheManager()
    {
        $this->login($this->manager);
        foreach (['hasEndDate', 'isContinuousFlow'] as $property) {
            $original = (bool) $this->fixed->$property;
            $this->fixed->$property = !$original;
            $this->assertContains($property, $this->lockedDates($this->fixed), "O gestor não muda {$property}");

            $this->fixed->$property = $original;
            $this->assertNotContains($property, $this->lockedDates($this->fixed), "Reenviar {$property} sem mudar segue valendo");
            $this->fixed->$property = !$original;
        }

        $this->login($this->superAdmin);
        $this->assertSame([], $this->lockedDates($this->fixed), 'O saasSuperAdmin segue mudando o fluxo');
    }

    function testForcingTheSaveDoesNotChangeTheDates()
    {
        $original = $this->fixed->registrationTo->format('Y-m-d H:i');
        $later = (clone $this->fixed->registrationTo)->modify('+1 day')->format('Y-m-d H:i');

        $this->patch($this->manager, $this->fixed, ['registrationTo' => $later], force: true);
        $this->assertSame($original, $this->fixed->refreshed()->registrationTo->format('Y-m-d H:i'), 'Com Force-Save, a data do gestor não é gravada');

        $this->patch($this->superAdmin, $this->fixed, ['registrationTo' => $later], force: true);
        $this->assertSame($later, $this->fixed->refreshed()->registrationTo->format('Y-m-d H:i'), 'A do saasSuperAdmin é');
    }

    function testTheAppealPhaseIsProtectedLikeTheOthers()
    {
        $appeal = $this->appealPhase();

        $this->assertFalse($appeal->canUser('remove', $this->manager), 'O gestor não exclui a fase de recurso');
        $this->assertFalse($appeal->canUser('modifyRegistrationFields', $this->manager), 'Nem mexe no formulário dela');

        $this->login($this->manager);
        $appeal->registrationTo = (clone $appeal->registrationTo)->modify('+1 day');
        $this->assertContains('registrationTo', $this->lockedDates($appeal), 'Nem nas datas');
    }

    function testTheFormIsLockedToTheManager()
    {
        $this->login($this->manager);

        $field = new RegistrationFieldConfiguration;
        $field->owner = $this->fixed;
        $field->fieldType = 'text';
        $field->title = 'Campo novo';
        $this->assertException(PermissionDenied::class, fn () => $field->save(true), 'O gestor não cria campo');

        $this->assertFalse($this->phase()->canUser('modifyRegistrationFields', $this->manager), 'Nem mexe nos campos e anexos da fase');

        $step = new RegistrationStep;
        $step->opportunity = $this->fixed;
        $step->name = 'Etapa nova';
        $this->assertException(PermissionDenied::class, fn () => $step->save(true), 'Nem cria etapa');

        $this->assertEquals(403, $this->statusOf('opportunity', 'importFields', [$this->fixed->id]), 'Nem importa campos');
        $this->assertEquals(403, $this->statusOf('opportunity', 'importFields', [$this->fixed->id], ['opportunityId' => 0]), 'Nem com outro id no corpo');

        $this->login($this->superAdmin);
        $field->save(true);
        $step->save(true);
        $this->assertNotNull($field->id, 'O saasSuperAdmin segue criando campo');

        $this->login($this->manager);
        $field = $field->refreshed();
        $field->title = 'Campo renomeado';
        $this->assertException(PermissionDenied::class, fn () => $field->save(true), 'O gestor não altera campo existente');
        $this->assertException(PermissionDenied::class, fn () => $field->refreshed()->delete(true), 'Nem o exclui');
        $this->assertException(PermissionDenied::class, fn () => $step->refreshed()->delete(true), 'Nem exclui etapa');
    }

    // O PATCH da fase inteira, com as datas reenviadas sem mudança e metadados novos, segue gravando.
    function testTheManagerKeepsSavingTheOtherSettings()
    {
        $evaluation = $this->fixed->evaluationMethodConfiguration;
        $dates = fn ($entity, array $properties) => array_map(fn ($property) => $entity->$property?->format('Y-m-d H:i'), array_combine($properties, $properties));

        $status = $this->patch($this->manager, $this->fixed, ['proponentSeals' => [1]] + $dates($this->fixed, ['registrationFrom', 'registrationTo']));
        $this->assertEquals(200, $status, 'O gestor salva a oportunidade com as mesmas datas');
        $this->assertEquals([1], (array) $this->fixed->refreshed()->proponentSeals, 'E um metadado novo');

        // Com Force-Save, as datas reenviadas da fase de avaliação não são recusadas.
        $this->patch($this->manager, $evaluation, ['valuersPerRegistration' => ['Moderação' => 1]] + $dates($evaluation, ['evaluationFrom', 'evaluationTo']), force: true);
        $this->assertStringNotContainsString('administração da plataforma', (string) $this->app->response->getBody(), 'A fase de avaliação não esbarra nas datas');
        $this->assertEquals(['Moderação' => 1], (array) $evaluation->refreshed()->valuersPerRegistration, 'E grava a distribuição da comissão');
    }

    function testTheManagerKeepsIncludingAndDisablingModerators()
    {
        $evaluation = $this->fixed->evaluationMethodConfiguration;
        $moderator = $this->moderator->refreshed();

        $this->assertFalse($moderator->canUser('remove', $this->manager), 'O gestor não exclui moderador');
        $this->assertEquals(403, $this->statusOf('opportunity', 'reopenEvaluations', [], ['opportunityId' => $this->fixed->id, 'uid' => $moderator->agent->user->id]), 'Nem reabre as avaliações dele');

        $this->login($this->manager);
        $this->assertException(PermissionDenied::class, fn () => $moderator->reopen(), 'Nem pela comissão');

        // Pelas mesmas rotas que os botões da comissão chamam.
        $this->assertEquals(200, $this->statusOf('evaluationMethodConfiguration', 'disableValuer', [$evaluation->id], ['relationId' => $moderator->id]));
        $this->assertEquals(EvaluationMethodConfigurationAgentRelation::STATUS_DISABLED, $moderator->refreshed()->status, 'Desabilita');

        $this->assertEquals(200, $this->statusOf('evaluationMethodConfiguration', 'enableValuer', [$evaluation->id], ['relationId' => $moderator->id]));
        $this->assertEquals(EvaluationMethodConfigurationAgentRelation::STATUS_ENABLED, $moderator->refreshed()->status, 'E habilita de novo');

        $this->login($this->manager);

        $included = $evaluation->createAgentRelation($this->userDirector->createUser()->profile, 'Moderação', true);
        $this->assertNotNull($included->id, 'E inclui');

        $this->assertTrue($moderator->canUser('remove', $this->superAdmin), 'O saasSuperAdmin segue excluindo');
    }
}
