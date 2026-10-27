<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Definitions\Metadata as MetadataDefinition;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\i;

/**
 * Ajustes na planilha de inscrições do core (job `registrations-spreadsheets`): a atuação e o festejo saem como texto de
 * célula; na oportunidade do programa, também as fotos, os vídeos, os links e as caixas de marcar, a consulta pede o
 * status e os dados dos agentes, saem as colunas sem resposta e entra a idade depois da data de nascimento.
 */
class RegistrationsSpreadsheet
{
    const JOB = 'registrations-spreadsheets';

    /** Pedidos em toda consulta da planilha do programa; o arquivo é escrito pelo cabeçalho, sem eles. */
    private const ALWAYS_SELECTED = ['status', 'agentsData'];

    private const AGE_COLUMN = 'mdcAge';

    // A coluna da data de nascimento, lida no cabeçalho e usada em cada lote.
    private ?string $birthColumn = null;

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

    /** @param array<string,string> $header coluna => título */
    function addAgeColumn(Opportunity $opportunity, array &$header): void
    {
        $this->birthColumn = null;

        if (!$this->fixedOpportunity->matches($opportunity)) {
            return;
        }

        foreach ($opportunity->firstPhase->registrationFieldConfigurations as $field) {
            if (($field->config['entityField'] ?? null) === 'dataDeNascimento' && isset($header[$field->fieldName])) {
                $this->birthColumn = $field->fieldName;
            }
        }

        if (!$this->birthColumn) {
            return;
        }

        $position = array_search($this->birthColumn, array_keys($header), true) + 1;
        $header = array_slice($header, 0, $position, true) + [self::AGE_COLUMN => i::__('Idade')] + array_slice($header, $position, null, true);
    }

    /** @param array<array<string,mixed>> $rows */
    function fillAge(array &$rows): void
    {
        if (!$this->birthColumn) {
            return;
        }

        $today = new \DateTimeImmutable('today');

        foreach ($rows as &$row) {
            $row[self::AGE_COLUMN] = self::age($row[$this->birthColumn] ?? null, $today);
        }
    }

    // Anos completos na data; a data vem como texto ("1958-03-20") ou já como data.
    static function age(mixed $birth, \DateTimeInterface $on): ?int
    {
        if ($birth instanceof \DateTimeInterface) {
            $date = $birth;
        } elseif (is_string($birth) && ($parsed = date_create_immutable(substr($birth, 0, 10)))) {
            $date = $parsed;
        } else {
            return null;
        }

        return $date > $on ? null : $date->diff($on)->y;
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

        // O festejo do Calendário pelo nome, e não pelo id.
        if ($field->fieldType === FestivityField::SLUG) {
            return [FestivityField::class, 'toText'];
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
