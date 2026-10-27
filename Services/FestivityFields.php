<?php

namespace MDC\Services;

use MapasCulturais\i;

/**
 * Os campos do festejo, que é um Evento do core: a chave, o tipo, as opções e o que é privado (só o aceite e o CNPJ).
 * As listas longas ficam em data/festejos-*.json. Nenhum campo é obrigatório aqui: cada etapa do formulário cobra os
 * seus.
 */
class FestivityFields
{
    const SEGMENT = 'mdcFestejoSegmento';

    const TYPOLOGIES = 'mdcFestejoTipologias';

    const OTHER_TYPOLOGY = 'mdcFestejoTipologiaOutra';

    const FIRST_YEAR = 'mdcFestejoPrimeiroAno';

    const EDITIONS = 'mdcFestejoEdicoes';

    // A data "de sempre", ou a da lei; a agenda de cada ano são as ocorrências do core.
    const USUAL_PERIOD = 'mdcFestejoPeriodoHabitual';

    const SIZE = 'mdcFestejoPorte';

    const VENUE = 'mdcFestejoTipoLocal';

    // Copiadas do primeiro local da agenda (Services\FestivityPlace::sync), para a busca e o mapa.
    const UF = 'mdcFestejoUf';

    const CITY = 'mdcFestejoCidade';

    const REGION = 'mdcFestejoRegiao';

    const TERRITORY = 'mdcFestejoTerritorio';

    const LAW = 'mdcFestejoLei';

    // O grupo de arquivo da lei própria, público como o resto do festejo.
    const LAW_FILE = 'mdcLegislacao';

    // Cada item como "Segmento: Ocupação".
    const ECONOMY = 'mdcFestejoEconomia';

    const OTHER_ECONOMY = 'mdcFestejoEconomiaOutra';

    const WITH_MASTERS = 'mdcFestejoComMestres';

    const CULTURE_POINT = 'mdcFestejoPonto';

    const CULTURE_POINT_NAME = 'mdcFestejoPontoNome';

    const ACCESSIBILITY = 'mdcFestejoAcessibilidade';

    const OTHER_ACCESSIBILITY = 'mdcFestejoAcessibilidadeOutra';

    const SHELTER = 'mdcFestejoAcolhimento';

    const OTHER_SHELTER = 'mdcFestejoAcolhimentoOutro';

    const PRODUCER = 'mdcFestejoRealizador';

    const INSTITUTION_IS_POINT = 'mdcFestejoInstituicaoPonto';

    const CONSENT = 'mdcFestejoAceite';

    /** @return array<string, array> a definição de cada metadado do evento */
    static function definitions(): array
    {
        $list = fn (array $values) => array_combine($values, $values);
        $yesNo = ['type' => 'select', 'options' => ['Sim' => i::__('Sim'), 'Não' => i::__('Não')]];

        $definitions = [
            self::SEGMENT => ['label' => i::__('Segmento'), 'type' => 'select', 'options' => $list(array_column(self::classification(), 'segmento'))],
            self::TYPOLOGIES => ['label' => i::__('Tipologias'), 'type' => 'multiselect', 'options' => $list(array_merge(...array_column(self::classification(), 'tipologias')))],
            self::OTHER_TYPOLOGY => ['label' => i::__('Outra tipologia'), 'type' => 'string'],
            self::FIRST_YEAR => ['label' => i::__('Ano da primeira realização'), 'type' => 'integer'],
            self::EDITIONS => ['label' => i::__('Edições realizadas'), 'type' => 'integer'],
            self::USUAL_PERIOD => ['label' => i::__('Quando costuma acontecer'), 'type' => 'string'],
            // As faixas da NBR 16004:2022.
            self::SIZE => ['label' => i::__('Porte'), 'type' => 'select', 'options' => [
                'Micro' => i::__('Micro: até 100 pessoas'),
                'Pequeno' => i::__('Pequeno: de 101 a 500 pessoas'),
                'Médio' => i::__('Médio: de 501 a 5.000 pessoas'),
                'Grande' => i::__('Grande: de 5.001 a 40.000 pessoas'),
                'Mega' => i::__('Mega: acima de 40.000 pessoas'),
            ]],
            self::VENUE => ['label' => i::__('Onde é realizado'), 'type' => 'select', 'options' => $list(['Espaço público', 'Espaço próprio', 'Espaço alugado', 'Itinerante'])],
            self::UF => ['label' => i::__('UF do festejo'), 'type' => 'string'],
            self::CITY => ['label' => i::__('Cidade do festejo'), 'type' => 'string'],
            self::REGION => ['label' => i::__('Região do festejo'), 'type' => 'string'],
            self::TERRITORY => ['label' => i::__('Território'), 'type' => 'select', 'options' => $list([
                'Urbano', 'Rural', 'Comunidade Quilombola', 'Território Indígena', 'Território Tradicional de Matriz Africana',
                'Periferia Urbana', 'Outro',
            ])],
            self::LAW => ['label' => i::__('Reconhecido por legislação própria')] + $yesNo,
            self::ECONOMY => ['label' => i::__('Atividades da economia criativa'), 'type' => 'multiselect', 'options' => $list(self::economyValues())],
            self::OTHER_ECONOMY => ['label' => i::__('Outra atividade da economia criativa'), 'type' => 'string'],
            self::WITH_MASTERS => ['label' => i::__('Com Mestras e Mestres')] + $yesNo,
            self::CULTURE_POINT => ['label' => i::__('Com Ponto ou Pontão de Cultura')] + $yesNo,
            self::CULTURE_POINT_NAME => ['label' => i::__('Ponto ou Pontão de Cultura'), 'type' => 'string'],
            self::ACCESSIBILITY => ['label' => i::__('Acessibilidade'), 'type' => 'multiselect', 'options' => $list(array_merge(...array_column(self::accessibility(), 'requisitos')))],
            self::OTHER_ACCESSIBILITY => ['label' => i::__('Outro recurso de acessibilidade'), 'type' => 'string'],
            self::SHELTER => ['label' => i::__('Espaços de acolhimento'), 'type' => 'multiselect', 'options' => $list(self::shelter())],
            self::OTHER_SHELTER => ['label' => i::__('Outro espaço de acolhimento'), 'type' => 'string'],
            self::PRODUCER => ['label' => i::__('Tipo de realizador'), 'type' => 'select', 'options' => $list([
                'Pessoa Física', 'Pessoa Jurídica com fins lucrativos', 'Pessoa Jurídica sem fins lucrativos',
                'Grupo/Coletivo Cultural', 'Comunidade (Festeiro)', 'Órgão Público',
            ])],
            self::INSTITUTION_IS_POINT => ['label' => i::__('A instituição é Ponto de Cultura')] + $yesNo,
            self::CONSENT => ['label' => i::__('Aceite da publicação'), 'type' => 'string', 'private' => true],
        ];

        // Para cada fonte: se o festejo teve recursos dela e, se sim, em que anos.
        foreach (self::fundingSources() as $key => $label) {
            $definitions[$key] = ['label' => sprintf(i::__('Recursos: %s'), $label)] + $yesNo;
            $definitions["{$key}Anos"] = ['label' => sprintf(i::__('Anos com recursos: %s'), $label), 'type' => 'string'];
        }

        return $definitions;
    }

    // A região sai da UF.
    static function region(string $uf): string
    {
        $regions = [
            'Norte' => ['AC', 'AM', 'AP', 'PA', 'RO', 'RR', 'TO'],
            'Nordeste' => ['AL', 'BA', 'CE', 'MA', 'PB', 'PE', 'PI', 'RN', 'SE'],
            'Centro-Oeste' => ['DF', 'GO', 'MS', 'MT'],
            'Sudeste' => ['ES', 'MG', 'RJ', 'SP'],
            'Sul' => ['PR', 'RS', 'SC'],
        ];

        foreach ($regions as $region => $ufs) {
            if (in_array(strtoupper($uf), $ufs, true)) {
                return $region;
            }
        }

        return '';
    }

    /** @return array<string, string> a chave de cada fonte de recursos públicos e o nome dela */
    static function fundingSources(): array
    {
        return [
            'mdcFestejoRouanet' => i::__('Lei Rouanet'),
            'mdcFestejoPnab' => i::__('PNAB'),
            'mdcFestejoIncentivoLocal' => i::__('Leis de incentivo dos estados, DF e municípios'),
            'mdcFestejoOutrasFontes' => i::__('Outras fontes públicas'),
        ];
    }

    /** @return list<array{segmento: string, tipologias: list<string>}> as tipologias por segmento */
    static function classification(): array
    {
        return self::load('festejos-classificacao');
    }

    /** @return list<array{segmento: string, ocupacoes: list<string>}> as ocupações da economia criativa por segmento */
    static function economy(): array
    {
        return self::load('festejos-economia');
    }

    /** @return list<array{tipo: string, requisitos: list<string>}> os requisitos de acessibilidade por tipo */
    static function accessibility(): array
    {
        return self::load('festejos-acessibilidade');
    }

    /** @return list<string> os espaços de acolhimento */
    static function shelter(): array
    {
        return self::load('festejos-acolhimento');
    }

    /** @return list<string> */
    private static function economyValues(): array
    {
        $values = [];

        foreach (self::economy() as $segment) {
            foreach ($segment['ocupacoes'] as $occupation) {
                $values[] = "{$segment['segmento']}: {$occupation}";
            }
        }

        return $values;
    }

    private static function load(string $name): array
    {
        static $loaded = [];

        return $loaded[$name] ??= json_decode(file_get_contents(dirname(__DIR__) . "/data/{$name}.json"), true, flags: JSON_THROW_ON_ERROR)['itens'];
    }
}
