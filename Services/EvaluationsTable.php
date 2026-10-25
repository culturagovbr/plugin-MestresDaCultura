<?php

namespace MDC\Services;

use MapasCulturais\i;

/**
 * Lista de avaliações do programa na gestão: a data de envio de cada parecer, como o Cultura Viva acrescenta a dele
 * (themes/CulturaViva/Theme.php, `component(opportunity-evaluations-table).additionalHeaders`). À vista pelo padrão
 * de colunas do programa (evaluations-table.js): o componente do core fixa as colunas visíveis no template.
 */
class EvaluationsTable
{
    /** O valor calculado em cada avaliação da API da lista (ver `format`). */
    const SENT = 'sentTimestampString';

    const SLUG = 'evaluationSentTimestamp';

    /** Os identificadores da tabela nas telas de todas as avaliações e das avaliações de um avaliador. */
    const TABLES = ['allEvaluationsTable', 'userEvaluationsTable'];

    // Logo depois do resultado do avaliador; no fim, se a coluna não vier.
    static function addColumn(array &$headers): void
    {
        $result = array_search('result', array_column($headers, 'slug'), true);
        $column = ['text' => i::__('Data de envio'), 'value' => 'evaluation?.' . self::SENT, 'slug' => self::SLUG];
        array_splice($headers, $result === false ? count($headers) : $result + 1, 0, [$column]);
    }

    static function format(array &$evaluations): void
    {
        foreach ($evaluations as &$evaluation) {
            if (is_array($evaluation) && array_key_exists('sentTimestamp', $evaluation)) {
                $evaluation[self::SENT] = $evaluation['sentTimestamp']?->format('d/m/Y H:i') ?? '';
            }
        }
        unset($evaluation);
    }
}
