<?php

namespace MDC\Services;

use MapasCulturais\i;

/**
 * Lista de cadastros do programa na gestão: a situação da moderação no lugar do resultado da avaliação, a última
 * atualização e sem as colunas que o programa não usa.
 */
class RegistrationsTable
{
    /** O valor calculado em cada linha da API da lista (ver `label`). */
    const MODERATION = 'mdcModeration';

    /** Colunas à vista no padrão do programa, cada uma depois da outra indicada. */
    const PLACED_AFTER = [self::MODERATION => 'agent', 'updateTimestamp' => 'status'];

    /** Pelo `slug` ou pelo `value` da coluna. */
    const UNUSED = [
        'consolidatedResult',
        'owner?.geoMesorregiao', 'owner?.geoEstado', 'owner?.geoMicrorregiao', 'owner?.geoMunicipio', 'owner?.geoPais',
        'category', 'proponentType', 'range', 'score', 'eligible', 'appliedPointReward', 'appliedForQuota',
        'workplanSnapshot', 'goalStatuses', 'workplanProxy',
        'rcv_pnab_registration', 'rcv_importer_row',
    ];

    static function adjust(array &$headers, string &$select): void
    {
        $headers = array_values(array_filter($headers, fn ($header) => !in_array($header['slug'] ?? null, self::UNUSED, true) && !in_array($header['value'] ?? null, self::UNUSED, true)));

        foreach ($headers as &$header) {
            if (($header['value'] ?? null) === 'updateTimestamp') {
                $header['text'] = i::__('Última atualização');
            }
        }
        unset($header);

        // Põe a coluna da situação depois da do agente.
        $headers = array_values(array_filter($headers, fn ($header) => ($header['slug'] ?? $header['value'] ?? null) !== self::MODERATION));
        $agent = array_search('agent', array_column($headers, 'slug'), true);
        $moderation = ['text' => i::__('Situação da moderação'), 'value' => self::MODERATION, 'slug' => self::MODERATION];
        array_splice($headers, $agent === false ? 0 : $agent + 1, 0, [$moderation]);

        // Pede na consulta o status, os campos reabertos e a última atualização.
        $fields = explode(',', $select);
        $select = implode(',', array_unique([...$fields, 'status', 'editableFields', 'editSentTimestamp', 'updateTimestamp']));
    }

    /**
     * Propriedade só de leitura da inscrição, que leva a situação da moderação da API à tela.
     */
    static function property(): array
    {
        return ['isMetadata' => false, 'isEntityRelation' => false, 'isReadonly' => true, 'label' => i::__('Situação da moderação')];
    }

    /** O nome da situação de uma linha da API da lista. */
    static function label(array $row): string
    {
        $situation = Moderation::situation((int) ($row['status'] ?? -1), $row['editableFields'] ?? null, $row['editSentTimestamp'] ?? null);

        return $situation ? Moderation::situationLabels()[$situation] : '';
    }
}
