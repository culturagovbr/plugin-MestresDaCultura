<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MapasCulturais\UserInterface;

class FixedOpportunity
{
    const CONFIG_KEY = 'mdc.opportunityId';

    /** Quantos níveis de pai a conferência sobe: raiz, fase e recurso, com folga. */
    private const MAX_DEPTH = 5;

    use ResolvesConfiguredEntity;

    function get(): ?Opportunity
    {
        return $this->resolveConfigured(
            self::CONFIG_KEY,
            'Opportunity',
            'MDC: a oportunidade fixa não está configurada. Defina MDC_OPPORTUNITY_ID no ambiente.',
            'MDC: a oportunidade fixa %d não existe nesta instalação. Confira MDC_OPPORTUNITY_ID.'
        );
    }

    /**
     * O subsite da oportunidade configurada (null, o site principal); false sem ela configurada ou no banco. Lido uma
     * vez por oportunidade.
     */
    static function configuredSubsiteId(): int|false|null
    {
        static $subsites = [];
        $app = App::i();
        $id = (int) ($app->config[self::CONFIG_KEY] ?? 0);

        if (!$id) {
            return false;
        }

        if (!array_key_exists($id, $subsites)) {
            $subsite = $app->conn->fetchOne('SELECT subsite_id FROM opportunity WHERE id = :id', ['id' => $id]);
            $subsites[$id] = $subsite === false ? false : ($subsite === null ? null : (int) $subsite);
        }

        return $subsites[$id];
    }

    /**
     * O subsite dos Mestres, lido no banco: o da oportunidade do programa (null, o site principal); antes de ela ser
     * configurada, o atual.
     */
    function subsiteId(): ?int
    {
        $app = App::i();
        $id = (int) ($app->config[self::CONFIG_KEY] ?? 0);
        $subsite = $id ? $app->conn->fetchOne('SELECT subsite_id FROM opportunity WHERE id = :id', ['id' => $id]) : false;

        if ($subsite === false) {
            return $app->getCurrentSubsiteId();
        }

        return $subsite === null ? null : (int) $subsite;
    }

    // Confere só a chave de configuração, sem avisar no log.
    function isConfigured(): bool
    {
        return (bool) (App::i()->config[self::CONFIG_KEY] ?? null);
    }

    // A oportunidade fixa ou uma das fases dela, em qualquer nível, pelo pai gravado.
    function matches(?Opportunity $opportunity): bool
    {
        $fixed = $this->get();

        for ($level = 0; $fixed && $opportunity && $level < self::MAX_DEPTH; $level++) {
            if ($opportunity->id && $opportunity->id === $fixed->id) {
                return true;
            }

            $opportunity = $this->storedParent($opportunity) ?? $opportunity->parent;
        }

        return false;
    }

    private function storedParent(Opportunity $opportunity): ?Opportunity
    {
        if (!$opportunity->id) {
            return null;
        }

        return App::i()->em->getUnitOfWork()->getOriginalEntityData($opportunity)['parent'] ?? null;
    }

    /**
     * A oportunidade e as fases dela, em qualquer status. Vazio sem oportunidade configurada.
     *
     * @return int[]
     */
    function ids(): array
    {
        $fixed = $this->get();

        if (!$fixed) {
            return [];
        }

        $ids = [$fixed->id];
        $level = [$fixed->id];

        while ($level) {
            $level = App::i()->em->createQuery('SELECT o.id FROM MapasCulturais\Entities\Opportunity o WHERE o.parent IN (:parents)')
                ->setParameter('parents', $level)
                ->getSingleColumnResult();
            $level = array_values(array_diff(array_map('intval', $level), $ids));
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    function includes(Registration $registration): bool
    {
        return $this->matches($registration->opportunity);
    }

    /**
     * Os cadastros da pessoa na oportunidade do programa, do rascunho em diante, em ordem de criação, lidos no banco
     * sem filtro de permissão.
     *
     * @return Registration[]
     */
    function registrationsOf(User $user): array
    {
        $fixed = $this->get();

        if (!$fixed) {
            return [];
        }

        return App::i()->em->createQuery(
            'SELECT r FROM MapasCulturais\Entities\Registration r
              JOIN r.owner a
             WHERE r.opportunity = :opportunity AND a.user = :user AND r.status >= 0
             ORDER BY r.id ASC'
        )
            ->setParameter('opportunity', $fixed)
            ->setParameter('user', $user)
            ->getResult();
    }

    // A fase do programa em que o usuário modera, com a relação habilitada na comissão.
    function moderatedBy(UserInterface $user): ?Opportunity
    {
        if ($user->is('guest')) {
            return null;
        }

        foreach ($this->get()?->allPhases ?? [] as $phase) {
            $relation = $phase->evaluationMethodConfiguration?->getUserRelation($user);

            if ($relation?->status === EvaluationMethodConfigurationAgentRelation::STATUS_ENABLED) {
                return $phase;
            }
        }

        return null;
    }
}
