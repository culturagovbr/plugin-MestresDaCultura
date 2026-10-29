<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
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

    use ResolvesConfiguredEntity;

    function get(): ?Seal
    {
        return $this->resolveConfigured(
            self::CONFIG_KEY,
            'Seal',
            'MDC: o selo do programa não está configurado. Defina MDC_SEAL_ID no ambiente.',
            'MDC: o selo %d não existe nesta instalação. Confira MDC_SEAL_ID.'
        );
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

    // Só a chave: get() avisa no log quando falta, e há quem pergunte a cada consulta.
    function configuredId(): ?int
    {
        return ((int) (App::i()->config[self::CONFIG_KEY] ?? 0)) ?: null;
    }

    // Compara com o id configurado, sem registrar erro.
    function isTheProgramSeal(Seal $seal): bool
    {
        return $this->configuredId() === $seal->id;
    }

    // Sem o selo configurado, false, sem registrar erro.
    function isGivenTo(int $agentId): bool
    {
        $id = $this->configuredId();

        return $id && App::i()->repo('AgentSealRelation')->findOneBy(['seal' => $id, 'owner' => $agentId]) !== null;
    }

    /**
     * Quem tem o selo entre os agentes dados, numa consulta só e na ordem dada; sem o selo configurado, ninguém.
     *
     * @param int[] $agentIds
     * @return list<int>
     */
    function givenAmong(array $agentIds): array
    {
        $id = $this->configuredId();
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));

        if (!$id || !$agentIds) {
            return [];
        }

        $given = App::i()->conn->fetchFirstColumn(
            'SELECT object_id FROM seal_relation WHERE seal_id = :seal AND object_type = :type AND object_id IN (:ids)',
            ['seal' => $id, 'type' => Agent::class, 'ids' => $agentIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        return array_values(array_intersect($agentIds, array_map('intval', $given)));
    }

    /** O selo do mestre, que é também o endereço do certificado. */
    function relationOf(Registration $registration): ?AgentSealRelation
    {
        return $this->relationOfAgent($registration->owner);
    }

    function relationOfAgent(Agent $agent): ?AgentSealRelation
    {
        $seal = $this->get();

        return $seal ? App::i()->repo('AgentSealRelation')->findOneBy(['seal' => $seal, 'owner' => $agent]) : null;
    }
}
