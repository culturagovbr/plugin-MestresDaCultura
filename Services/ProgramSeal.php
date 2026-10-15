<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\AgentSealRelation;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\Seal;

/**
 * Selo do programa ("Mestra e Mestre da Cultura"): marca o agente do mestre aprovado e dá a página de certificado do
 * core. O id vem de MDC_SEAL_ID; o plugin aplica e retira o selo.
 */
class ProgramSeal
{
    const CONFIG_KEY = 'mdc.sealId';

    private bool $resolved = false;

    private ?Seal $seal = null;

    function get(): ?Seal
    {
        if ($this->resolved) {
            return $this->seal;
        }

        $this->resolved = true;

        $app = App::i();
        $id = $app->config[self::CONFIG_KEY] ?? null;

        if (!$id) {
            $app->log->error('MDC: o selo do programa não está configurado. Defina MDC_SEAL_ID no ambiente.');

            return null;
        }

        $seal = $app->repo('Seal')->find((int) $id);

        if (!$seal) {
            $app->log->error("MDC: o selo {$id} não existe nesta instalação. Confira MDC_SEAL_ID.");

            return null;
        }

        return $this->seal = $seal;
    }

    // Emite o selo em nome do dono do selo.
    function apply(Registration $registration): void
    {
        $seal = $this->get();

        if (!$seal) {
            return;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            $registration->owner->createSealRelation($seal, true, true, $seal->owner);
        } finally {
            $app->enableAccessControl();
        }
    }

    function remove(Registration $registration): void
    {
        $seal = $this->get();

        if (!$seal) {
            return;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            $registration->owner->removeSealRelation($seal);
        } finally {
            $app->enableAccessControl();
        }
    }

    /** O selo do mestre, que é também o endereço do certificado. */
    function relationOf(Registration $registration): ?AgentSealRelation
    {
        $seal = $this->get();

        return $seal ? App::i()->repo('AgentSealRelation')->findOneBy(['seal' => $seal, 'owner' => $registration->owner]) : null;
    }
}
