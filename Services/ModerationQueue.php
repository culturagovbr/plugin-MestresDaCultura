<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Request;
use MDC\Jobs\ReleaseStaleDrafts;
use Opportunities\Jobs\RedistributeCommitteeRegistrations;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Devolve à fila da comissão o cadastro com moderador desabilitado ou parado: apaga o rascunho e pede a redistribuição.
 * Concluídas e enviadas não são tocadas; sem moderador ativo, o cadastro fica na fila.
 */
class ModerationQueue
{
    /** Opções da fase de moderação, registradas em todos os subsites. */
    const RELEASE_DISABLED = 'mdcReleaseDisabledDrafts';

    const RELEASE_STALE = 'mdcReleaseStaleDrafts';

    const STALE_DAYS = 'mdcStaleDraftDays';

    /** Dias sem alteração para o rascunho voltar à fila: 7, de 1 a 180. */
    const DEFAULT_STALE_DAYS = 7;

    const STALE_DAYS_RANGE = [1, 180];

    function __construct(private FixedOpportunity $fixedOpportunity = new FixedOpportunity)
    {
    }

    /**
     * O moderador foi desabilitado: apaga os rascunhos dele e redistribui a comissão na hora. Nada no "Substituir
     * avaliador" nem na distribuição manual; os rascunhos ficam se ele segue habilitado em outra relação da comissão.
     */
    function releaseFrom(EvaluationMethodConfigurationAgentRelation $relation): void
    {
        if ($relation->__skipRedistribution || $this->isManual($relation->owner)) {
            return;
        }

        if ($relation->owner->{self::RELEASE_DISABLED} && !$this->hasAnotherEnabledRelation($relation)) {
            $evaluations = App::i()->repo('RegistrationEvaluation')->findByOpportunityAndUser($relation->owner->opportunity, $relation->agent->user, $relation->group);
            $this->delete(array_filter($evaluations, fn ($evaluation) => $evaluation->status === RegistrationEvaluation::STATUS_DRAFT));
        }

        $this->redistribute($relation->owner);
    }

    /** Apaga a avaliação do moderador desabilitado e redistribui o cadastro. */
    function releaseEvaluation(RegistrationEvaluation $evaluation): void
    {
        $configuration = $evaluation->registration->evaluationMethodConfiguration;
        $this->delete([$evaluation]);
        $this->redistribute($configuration);
    }

    function isActive(RegistrationEvaluation $evaluation): bool
    {
        return $this->relationOf($evaluation)?->status === EvaluationMethodConfigurationAgentRelation::STATUS_ENABLED;
    }

    /**
     * Antes de cada redistribuição da moderação, apaga os rascunhos de quem está desabilitado e os parados de quem está
     * ativo, cada um conforme a sua opção.
     */
    function releasePhase(EvaluationMethodConfiguration $configuration): void
    {
        if ($this->isManual($configuration)) {
            return;
        }

        $drafts = [];

        if ($configuration->{self::RELEASE_DISABLED}) {
            foreach ($configuration->getAgentRelations(null, false) as $relation) {
                if ($relation->status === EvaluationMethodConfigurationAgentRelation::STATUS_DISABLED && !$this->hasAnotherEnabledRelation($relation)) {
                    foreach (App::i()->repo('RegistrationEvaluation')->findByOpportunityAndUser($configuration->opportunity, $relation->agent->user, $relation->group) as $evaluation) {
                        if ($evaluation->status === RegistrationEvaluation::STATUS_DRAFT) {
                            $drafts[$evaluation->id] = $evaluation;
                        }
                    }
                }
            }
        }

        if ($configuration->{self::RELEASE_STALE}) {
            foreach ($this->staleDrafts($configuration) as $evaluation) {
                if ($this->isActive($evaluation)) {
                    $drafts[$evaluation->id] = $evaluation;
                }
            }
        }

        $this->delete($drafts);
    }

    /** O job diário: com rascunho parado na moderação, pede a redistribuição, que o libera antes (ver releasePhase). */
    function releaseStaleDrafts(): void
    {
        $configuration = $this->fixedOpportunity->isConfigured() ? $this->fixedOpportunity->get()?->evaluationMethodConfiguration : null;

        if ($configuration?->{self::RELEASE_STALE} && $this->staleDrafts($configuration)) {
            $this->redistribute($configuration);
        }
    }

    /** @return RegistrationEvaluation[] rascunhos sem mudança há mais dias que os configurados; sem data de alteração, conta da criação */
    private function staleDrafts(EvaluationMethodConfiguration $configuration): array
    {
        return App::i()->em->createQuery(
            'SELECT e FROM MapasCulturais\Entities\RegistrationEvaluation e JOIN e.registration r
              WHERE r.opportunity = :opportunity AND e.status = :draft AND COALESCE(e.updateTimestamp, e.createTimestamp) <= :limit'
        )
            ->setParameter('opportunity', $configuration->opportunity)
            ->setParameter('draft', RegistrationEvaluation::STATUS_DRAFT)
            ->setParameter('limit', new \DateTime('-' . self::staleDays($configuration) . ' days'))
            ->getResult();
    }

    // Fora da faixa, vale o padrão.
    static function staleDays(EvaluationMethodConfiguration $configuration): int
    {
        $days = (int) $configuration->{self::STALE_DAYS};
        [$min, $max] = self::STALE_DAYS_RANGE;

        return $days >= $min && $days <= $max ? $days : self::DEFAULT_STALE_DAYS;
    }

    private function isManual(EvaluationMethodConfiguration $configuration): bool
    {
        return ($configuration->distributionConfiguration ?? 'deactivate') === 'deactivate';
    }

    // O mesmo usuário pode ter outra relação, habilitada, na mesma comissão.
    private function hasAnotherEnabledRelation(EvaluationMethodConfigurationAgentRelation $relation): bool
    {
        foreach ($relation->owner->getAgentRelations(null, false) as $other) {
            $sameModerator = $other->id !== $relation->id && $other->group === $relation->group && $other->agent->user->id === $relation->agent->user->id;

            if ($sameModerator && $other->status === EvaluationMethodConfigurationAgentRelation::STATUS_ENABLED) {
                return true;
            }
        }

        return false;
    }

    private function relationOf(RegistrationEvaluation $evaluation): ?EvaluationMethodConfigurationAgentRelation
    {
        return App::i()->repo('EvaluationMethodConfigurationAgentRelation')->findOneBy([
            'owner' => $evaluation->registration->evaluationMethodConfiguration,
            'agent' => $evaluation->user->profile,
            'group' => $evaluation->committee,
        ]);
    }




    /**
     * Apaga as avaliações sem checar permissão; em job, com uma requisição de fundo, sem IP, para o log do MapasBlame.
     *
     * @param RegistrationEvaluation[] $evaluations
     */
    private function delete(array $evaluations): void
    {
        $app = App::i();
        $request = $app->request;
        $app->disableAccessControl();
        $app->request = $request ?: new Request((new ServerRequestFactory)->createServerRequest('GET', '/'), 'job', ReleaseStaleDrafts::SLUG, []);

        try {
            foreach ($evaluations as $evaluation) {
                $evaluation->delete(true);
            }
        } finally {
            $app->request = $request;
            $app->enableAccessControl();
        }
    }

    /**
     * Agenda a redistribuição da comissão para agora, salvo na distribuição manual ou com outra já rodando.
     */
    private function redistribute(EvaluationMethodConfiguration $configuration): void
    {
        $app = App::i();

        if ($this->isManual($configuration)) {
            return;
        }

        $running = $app->conn->fetchOne("SELECT count(*) FROM job WHERE name = :name AND status = 1 AND metadata::text LIKE :configuration", [
            'name' => RedistributeCommitteeRegistrations::SLUG,
            'configuration' => '%EvaluationMethodConfiguration:' . $configuration->id . '"%',
        ]);

        if (!$running) {
            $app->enqueueOrReplaceJob(RedistributeCommitteeRegistrations::SLUG, ['evaluationMethodConfiguration' => $configuration], 'now');
        }
    }
}
