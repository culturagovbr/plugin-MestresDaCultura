<?php

namespace MDC\Services;

use MapasCulturais\i;

/**
 * Lista de avaliações do programa, do gestor e do moderador: os textos da moderação, sem as colunas que o programa não
 * usa e com a data de envio de cada parecer.
 */
class EvaluationsTable
{
    /** O valor calculado em cada avaliação da API da lista (ver `format`). */
    const SENT = 'sentTimestampString';

    const SLUG = 'evaluationSentTimestamp';

    /** Os identificadores da tabela nas telas de todas as avaliações e das avaliações de um avaliador. */
    const TABLES = ['allEvaluationsTable', 'userEvaluationsTable'];

    /** Pelo `slug` ou pelo `value` da coluna: as da lista de cadastros e o coletivo do Cultura Viva. */
    const UNUSED = [...RegistrationsTable::UNUSED, 'coletivo'];

    /** Os textos da moderação, pelo `slug` da coluna do core. */
    const TEXTS = [
        'committeeSequentialNumber' => 'Nº moderador',
        'valuerUserId' => 'ID usuário do moderador',
        'valuerAgentId' => 'ID agente do moderador',
        'evaluator' => 'Moderador',
    ];

    /**
     * `$own`: a lista do próprio moderador, sem as colunas sobre ele; senão, a do gestor, com todas as avaliações.
     */
    static function adjust(array &$headers, bool $own): void
    {
        $unused = $own ? [...self::UNUSED, ...array_keys(self::TEXTS)] : self::UNUSED;
        $headers = array_values(array_filter($headers, fn ($header) => !in_array($header['slug'] ?? null, $unused, true) && !in_array($header['value'] ?? null, $unused, true)));
        // Na lista do próprio moderador, o número também leva o vocabulário da moderação.
        $texts = [...self::TEXTS, 'result' => $own ? 'Resultado da sua moderação' : 'Resultado da moderação'] + ($own ? ['number' => 'Número do cadastro'] : []);

        foreach ($headers as &$header) {
            if (isset($texts[$header['slug'] ?? ''])) {
                $header['text'] = i::__($texts[$header['slug']]);
            }
        }
        unset($header);

        self::addColumn($headers);
    }

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
