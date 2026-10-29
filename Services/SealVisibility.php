<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\UserInterface;

/**
 * Os selos que cada papel vê no subsite dos Mestres (os ganchos em Hooks\SubsiteIsolation): quem não é admin, o selo
 * dos mestres e o dos festejos; o gestor e o saasSuperAdmin, os selos do subsite (o do programa e os que criarem nele).
 */
class SealVisibility
{
    function __construct(
        private ProgramSeal $programSeal,
        private FestivitySeal $festivitySeal,
        private FixedOpportunity $fixedOpportunity,
    ) {
    }

    /** @return ?int[] os ids que a pessoa vê; null, sem filtro (sem o selo do programa configurado) */
    function visibleTo(UserInterface $user): ?array
    {
        if (!$user->is('admin')) {
            return $this->programSeal->configuredId() ? array_values(array_filter([$this->programSeal->configuredId(), $this->festivitySeal->configuredId()])) : null;
        }

        $subsite = $this->fixedOpportunity->subsiteId();
        $sql = $subsite === null ? 'SELECT id FROM seal WHERE subsite_id IS NULL' : 'SELECT id FROM seal WHERE subsite_id = :subsite';

        return array_map('intval', App::i()->conn->fetchFirstColumn($sql, ['subsite' => $subsite]));
    }

    /**
     * Tira dos agentes (como a API e a página do perfil os entregam) os selos que a pessoa não vê.
     *
     * @param array<array> $agents
     * @param int[] $ids
     */
    static function keepVisible(array &$agents, array $ids): void
    {
        foreach ($agents as &$agent) {
            foreach (['seals', 'verifiedSeals'] as $key) {
                if (is_array($agent[$key] ?? null)) {
                    $agent[$key] = array_values(array_filter($agent[$key], fn ($seal) => in_array((int) ($seal['sealId'] ?? 0), $ids, true)));
                }
            }
        }
    }
}
