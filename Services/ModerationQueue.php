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
 * D4: o cadastro que está com um moderador que não vai analisá-lo volta para a fila da comissão. Na distribuição, o
 * core mantém toda avaliação existente com quem a começou, mesmo desabilitado ou parado; aqui o rascunho é apagado e
 * o cadastro passa a outro moderador. Concluídas e enviadas não são tocadas. Sem moderador ativo, o cadastro fica na
 * fila até alguém ser habilitado. Segue themes/CulturaViva/EvaluationDistribution.php, que faz o mesmo no Cultura
 * Viva; aqui a devolução dos parados tem relógio (job diário) e prazo fixo, e o parado vai direto a outro moderador.
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
     * Rascunhos da moderação sem mudança há mais dias que os configurados na fase voltam para a fila: o cadastro passa direto ao
     * moderador ativo da comissão com menos cadastros; sendo quem o deixou parado o único, volta para ele. Sem
     * redistribuir a comissão, que equilibra a carga e poderia devolvê-lo a quem o deixou parado.
     */
    function releaseStaleDrafts(): void
    {
        $configuration = $this->fixedOpportunity->isConfigured() ? $this->fixedOpportunity->get()?->evaluationMethodConfiguration : null;

        if (!$configuration?->{self::RELEASE_STALE}) {
            return;
        }

        $app = App::i();
        // Sem data de alteração, conta da criação.
        $stale = $app->em->createQuery(
            'SELECT e FROM MapasCulturais\Entities\RegistrationEvaluation e JOIN e.registration r
              WHERE r.opportunity = :opportunity AND e.status = :draft AND COALESCE(e.updateTimestamp, e.createTimestamp) <= :limit'
        )
            ->setParameter('opportunity', $configuration->opportunity)
            ->setParameter('draft', RegistrationEvaluation::STATUS_DRAFT)
            ->setParameter('limit', new \DateTime('-' . self::staleDays($configuration) . ' days'))
            ->getResult();

        foreach ($stale as $evaluation) {
            /** @var RegistrationEvaluation $evaluation */
            $registration = $evaluation->registration;
            $committee = $evaluation->committee;
            $idle = $evaluation->user->id;
            $this->delete([$evaluation]);
            $this->assign($registration, $committee, $this->leastBusy($configuration, $committee, $idle) ?? $idle);
        }

        if ($stale) {
            $this->updateSummaries($configuration);
        }
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

    /** O moderador ativo da comissão, que não seja `$except`, com menos cadastros. */
    private function leastBusy(EvaluationMethodConfiguration $configuration, string $committee, int $except): ?int
    {
        $load = [];

        foreach ($configuration->getAgentRelations(null, false) as $relation) {
            $user = $relation->agent->user->id;

            if ($relation->status === EvaluationMethodConfigurationAgentRelation::STATUS_ENABLED && $relation->group === $committee && $user !== $except) {
                $load[$user] = (int) App::i()->conn->fetchOne(
                    'SELECT count(*) FROM registration WHERE opportunity_id = :opportunity AND status > 0 AND jsonb_exists(valuers::jsonb, :user)',
                    ['opportunity' => $configuration->opportunity->id, 'user' => (string) $user]
                );
            }
        }

        asort($load);

        return array_key_first($load);
    }

    // Como a distribuição do core grava: direto na coluna, com o moderador e a comissão.
    private function assign(Registration $registration, string $committee, int $user): void
    {
        App::i()->conn->update('registration', ['valuers' => json_encode([(string) $user => $committee])], ['id' => $registration->id]);
    }

    // Como no fim da distribuição do core: os resumos da fase e de cada moderador.
    private function updateSummaries(EvaluationMethodConfiguration $configuration): void
    {
        $app = App::i();
        $app->mscache->delete($configuration->summaryCacheKey);
        $configuration->getSummary(true);

        foreach ($configuration->getAgentRelations() as $relation) {
            $relation->updateSummary();
        }
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
