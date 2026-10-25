<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;
use MapasCulturais\UserInterface;

/**
 * Os blocos do painel inicial conforme o papel: ao moderador, o resumo do que está com ele
 * (EvaluationMethodConfiguration::getValuerSummary); a quem controla o programa, os atalhos de gestão e os cadastros
 * por situação da moderação.
 */
class PanelSummary
{
    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    /** @return array{moderation: ?array, management: ?array} */
    function for(UserInterface $user): array
    {
        if ($user->is('guest') || !$this->fixedOpportunity->isConfigured() || !($fixed = $this->fixedOpportunity->get())) {
            return ['moderation' => null, 'management' => null];
        }

        return ['moderation' => $this->moderation($user), 'management' => $fixed->canUser('@control') ? $this->management($fixed) : null];
    }

    /**
     * Os atalhos da Gestão do cadastro, os mesmos do menu do painel (MDC\Plugin::addManagementShortcuts).
     *
     * @return array<array{route: string, params?: array, icon: string, label: string}>
     */
    static function shortcuts(Opportunity $fixed): array
    {
        $items = [['route' => 'opportunity/registrations', 'params' => [$fixed->id], 'icon' => 'registration', 'label' => i::__('Cadastros')]];

        if ($fixed->evaluationMethodConfiguration) {
            $items[] = ['route' => 'opportunity/allEvaluations', 'params' => [$fixed->id], 'icon' => 'check', 'label' => i::__('Avaliações')];
            $items[] = ['route' => 'panel/mdc-committee', 'icon' => 'agents', 'label' => i::__('Comissão')];
        }

        return $items;
    }

    /**
     * Quantos cadastros do programa em cada situação da moderação, numa consulta, pela regra de Moderation::situation.
     *
     * @return array<string, int>
     */
    static function countsBySituation(int $opportunityId): array
    {
        $rows = App::i()->conn->fetchAllKeyValue("
            SELECT situation, count(*) FROM (
                SELECT CASE
                    WHEN status = 0 THEN 'draft'
                    WHEN status = 10 THEN 'approved'
                    WHEN status = 1 AND (editable_fields IS NULL OR editable_fields::text IN ('null', '[]', '{}', '\"\"')) THEN 'waiting'
                    WHEN status = 1 AND edit_sent_timestamp IS NOT NULL THEN 'resent'
                    WHEN status = 1 THEN 'returned'
                END AS situation
                FROM registration WHERE opportunity_id = :opportunity
            ) situations
            WHERE situation IS NOT NULL
            GROUP BY situation", ['opportunity' => $opportunityId]);

        return array_map('intval', array_replace(array_fill_keys(Moderation::SITUATIONS, 0), array_intersect_key($rows, array_flip(Moderation::SITUATIONS))));
    }

    private function moderation(UserInterface $user): ?array
    {
        $phase = $this->fixedOpportunity->moderatedBy($user);
        $committee = $phase?->evaluationMethodConfiguration;
        $relation = $committee?->getUserRelation($user);

        if (!$relation) {
            return null;
        }

        return [
            'counts' => $committee->getValuerSummary($user, $relation->group),
            'url' => App::i()->createUrl('opportunity', 'userEvaluations', [$phase->id]),
        ];
    }

    private function management(Opportunity $fixed): array
    {
        $app = App::i();
        $shortcuts = array_map(function ($item) use ($app) {
            [$controller, $action] = explode('/', $item['route']);

            return ['label' => $item['label'], 'icon' => $item['icon'], 'url' => $app->createUrl($controller, $action, $item['params'] ?? [])];
        }, self::shortcuts($fixed));

        return [
            'shortcuts' => $shortcuts,
            'counts' => self::countsBySituation($fixed->id),
            'labels' => Moderation::situationLabels(),
        ];
    }
}
