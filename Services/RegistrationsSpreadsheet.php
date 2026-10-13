<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Definitions\Metadata as MetadataDefinition;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\i;

/**
 * Ajustes na planilha de inscrições do core (job `registrations-spreadsheets`, módulo Spreadsheets), que
 * junta valores em lista com vírgula e apaga quebras de linha:
 * - a atuação sairia "Array, Array": enquanto o job monta cada lote, ela é lida como texto de célula;
 * - na oportunidade do programa, o mesmo vale para fotos (em branco), vídeos ("Array"), links (num link só,
 *   que não abre) e caixas de marcar ("1" ou vazio); a consulta sempre pede o status, sem o qual os campos do agente saem do perfil atual, e
 *   não do que foi enviado, e os dados dos agentes, sem os quais o responsável sai em branco; e as colunas
 *   que nunca têm resposta (seções do formulário e nome do projeto) saem do arquivo.
 */
class RegistrationsSpreadsheet
{
    const JOB = 'registrations-spreadsheets';

    /** Pedidos em toda consulta da planilha do programa; o arquivo é escrito pelo cabeçalho, sem eles. */
    private const ALWAYS_SELECTED = ['status', 'agentsData'];

    private bool $exporting = false;

    /** @var array<string,array{MetadataDefinition, ?callable}> campo => [definição, leitura original] */
    private array $replaced = [];

    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    /**
     * Tira do cabeçalho, e assim da planilha, o nome do projeto e as seções do formulário de todas as fases.
     *
     * @param array<string,string> $header coluna => título
     */
    function removeEmptyColumns(Opportunity $opportunity, array &$header): void
    {
        if (!$this->fixedOpportunity->matches($opportunity)) {
            return;
        }

        // O programa não usa projeto.
        unset($header['projectName']);

        for ($phase = $opportunity; $phase; $phase = $phase->previousPhase) {
            foreach ($phase->registrationFieldConfigurations as $field) {
                if ($field->fieldType === 'section') {
                    unset($header[$field->fieldName]);
                }
            }
        }
    }

    function startBatch(Opportunity $opportunity, array &$query): void
    {
        $this->exporting = true;

        if ($this->fixedOpportunity->matches($opportunity)) {
            $select = array_values(array_filter(array_map('trim', explode(',', $query['@select'] ?? ''))));
            $query['@select'] = implode(',', array_unique([...$select, ...self::ALWAYS_SELECTED]));
        }
    }

    // Troca, durante o lote, a leitura dos campos da fase pela de texto de célula.
    function readAsCells(Opportunity $phase): void
    {
        if (!$this->exporting) {
            return;
        }

        $definitions = App::i()->getRegisteredMetadata(Registration::class);

        foreach ($phase->registrationFieldConfigurations as $field) {
            $definition = $definitions[$field->fieldName] ?? null;
            $cell = $this->cellFor($field, $phase);

            if (!$definition || !$cell || isset($this->replaced[$field->fieldName])) {
                continue;
            }

            $original = $definition->unserialize;
            $this->replaced[$field->fieldName] = [$definition, $original];

            $definition->unserialize = fn ($value, ...$args) => $cell($original ? $original($value, ...$args) : $value);
        }
    }

    // Devolve a leitura original dos campos.
    function finishBatch(): void
    {
        foreach ($this->replaced as [$definition, $original]) {
            $definition->unserialize = $original;
        }

        $this->exporting = false;
        $this->replaced = [];
    }

    private function cellFor(RegistrationFieldConfiguration $field, Opportunity $phase): ?\Closure
    {
        // Valor fora do formato esperado segue como veio.
        $list = fn (callable $format) => fn ($value) => is_array($value) ? $format($value) : $value;

        if ($field->fieldType === ActingField::SLUG) {
            return $list([ActingField::class, 'toCell']);
        }

        if (!$this->fixedOpportunity->matches($phase)) {
            return null;
        }

        // A caixa desmarcada é gravada como '': é a resposta "Não", e não uma célula em branco.
        if ($field->fieldType === 'checkbox') {
            return fn ($value) => $value === null ? null : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? i::__('Sim') : i::__('Não'));
        }

        return match ($field->config['entityField'] ?? null) {
            AgentMediaView::GALLERY => $list([AgentMediaView::class, 'photosAsCell']),
            AgentMediaView::VIDEOS, AgentMediaView::LINKS => $list([AgentMediaView::class, 'linksAsCell']),
            default => null,
        };
    }
}
