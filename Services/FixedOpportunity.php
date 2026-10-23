<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;

class FixedOpportunity
{
    const CONFIG_KEY = 'mdc.opportunityId';

    /** Quantos níveis de pai a conferência sobe: raiz, fase e recurso, com folga. */
    private const MAX_DEPTH = 5;

    private bool $resolved = false;

    private ?Opportunity $opportunity = null;

    function get(): ?Opportunity
    {
        if ($this->resolved) {
            return $this->opportunity;
        }

        $this->resolved = true;

        $app = App::i();
        $id = $app->config[self::CONFIG_KEY] ?? null;

        if (!$id) {
            $app->log->error('MDC: a oportunidade fixa não está configurada. Defina MDC_OPPORTUNITY_ID no ambiente.');

            return null;
        }

        $opportunity = $app->repo('Opportunity')->find((int) $id);

        if (!$opportunity) {
            $app->log->error("MDC: a oportunidade fixa {$id} não existe nesta instalação. Confira MDC_OPPORTUNITY_ID.");

            return null;
        }

        return $this->opportunity = $opportunity;
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
}
