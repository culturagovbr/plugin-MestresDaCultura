<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;

class FixedOpportunity
{
    const CONFIG_KEY = 'mdc.opportunityId';

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

    // A inscrição pode estar numa fase, que é filha da oportunidade fixa.
    function includes(Registration $registration): bool
    {
        $fixed = $this->get();
        $opportunity = $registration->opportunity;
        $root = $opportunity?->parent ?: $opportunity;

        return $fixed && $root?->id === $fixed->id;
    }
}
