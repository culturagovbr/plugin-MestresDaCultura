<?php

namespace MDC\Services;

use MapasCulturais\App;
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

    // As mestras e os mestres da Rede ligados ao festejo, como "|12|34|"; só quem tem o selo do programa
    // (FestivityForm::invalid).
    const MASTERS = 'mdcFestejoMestres';

    const CULTURE_POINT = 'mdcFestejoPonto';

    const CULTURE_POINT_NAME = 'mdcFestejoPontoNome';

    // Acessibilidade e acolhimento: a pergunta (Sim/Não) e, no "Sim", a lista.
    const HAS_ACCESSIBILITY = 'mdcFestejoTemAcessibilidade';

    const ACCESSIBILITY = 'mdcFestejoAcessibilidade';

    const OTHER_ACCESSIBILITY = 'mdcFestejoAcessibilidadeOutra';

    const HAS_SHELTER = 'mdcFestejoTemAcolhimento';

    const SHELTER = 'mdcFestejoAcolhimento';

    const OTHER_SHELTER = 'mdcFestejoAcolhimentoOutro';

    const PRODUCER = 'mdcFestejoRealizador';

    // A instituição de quem realiza fica no festejo: o nome é público, o CNPJ não.
    const INSTITUTION = 'mdcFestejoInstituicao';

    const CNPJ = 'mdcFestejoCnpj';

    const INSTITUTION_IS_POINT = 'mdcFestejoInstituicaoPonto';

    // Quem realiza com instituição (nome) e, dentre eles, com CNPJ.
    const WITH_INSTITUTION = ['Pessoa Jurídica com fins lucrativos', 'Pessoa Jurídica sem fins lucrativos', 'Grupo/Coletivo Cultural', 'Órgão Público'];

    const WITH_CNPJ = ['Pessoa Jurídica com fins lucrativos', 'Pessoa Jurídica sem fins lucrativos', 'Órgão Público'];

    const CONSENT = 'mdcFestejoAceite';

    // A data da primeira publicação, de onde o certificado conta (FestivitySeal).
    const FIRST_PUBLISHED = 'mdcFestejoPublicadoEm';

    // As listas que têm "Outro": a lista => o campo do texto. Uma das duas basta.
    const OTHERS = [
        self::TYPOLOGIES => self::OTHER_TYPOLOGY,
        self::ECONOMY => self::OTHER_ECONOMY,
        self::ACCESSIBILITY => self::OTHER_ACCESSIBILITY,
        self::SHELTER => self::OTHER_SHELTER,
    ];

    // Itens da acessibilidade que o evento do core também tem como campo.
    const CORE_ACCESSIBILITY = [
        'Intérprete de libras' => 'traducaoLibras',
        'Audiodescrição' => 'descricaoSonora',
    ];

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
            self::MASTERS => ['label' => i::__('Mestras e Mestres da Rede no festejo'), 'type' => 'string'],
            self::CULTURE_POINT => ['label' => i::__('Com Ponto ou Pontão de Cultura')] + $yesNo,
            self::CULTURE_POINT_NAME => ['label' => i::__('Ponto ou Pontão de Cultura'), 'type' => 'string'],
            self::HAS_ACCESSIBILITY => ['label' => i::__('Atende a requisitos de acessibilidade')] + $yesNo,
            self::ACCESSIBILITY => ['label' => i::__('Acessibilidade'), 'type' => 'multiselect', 'options' => $list(array_merge(...array_column(self::accessibility(), 'requisitos')))],
            self::OTHER_ACCESSIBILITY => ['label' => i::__('Outro recurso de acessibilidade'), 'type' => 'string'],
            self::HAS_SHELTER => ['label' => i::__('Tem espaços de acolhimento')] + $yesNo,
            self::SHELTER => ['label' => i::__('Espaços de acolhimento'), 'type' => 'multiselect', 'options' => $list(self::shelter())],
            self::OTHER_SHELTER => ['label' => i::__('Outro espaço de acolhimento'), 'type' => 'string'],
            self::PRODUCER => ['label' => i::__('Tipo de realizador'), 'type' => 'select', 'options' => $list([
                'Pessoa Física', 'Pessoa Jurídica com fins lucrativos', 'Pessoa Jurídica sem fins lucrativos',
                'Grupo/Coletivo Cultural', 'Comunidade (Festeiro)', 'Órgão Público',
            ])],
            self::INSTITUTION => ['label' => i::__('Nome da instituição ou órgão'), 'type' => 'string'],
            self::CNPJ => ['label' => i::__('CNPJ'), 'type' => 'cnpj', 'private' => true, 'validations' => ['v::cnpj()' => i::__('O CNPJ é inválido')]],
            self::INSTITUTION_IS_POINT => ['label' => i::__('A instituição é Ponto de Cultura')] + $yesNo,
            self::CONSENT => ['label' => i::__('Aceite da publicação'), 'type' => 'string', 'private' => true],
            self::FIRST_PUBLISHED => ['label' => i::__('No Calendário desde'), 'type' => 'string'],
        ];

        // Para cada fonte: se o festejo teve recursos dela e, se sim, em que anos.
        foreach (self::fundingSources() as $key => $label) {
            $definitions[$key] = ['label' => sprintf(i::__('Recursos: %s'), $label)] + $yesNo;
            $definitions["{$key}Anos"] = ['label' => sprintf(i::__('Anos com recursos: %s'), $label), 'type' => 'string'];
        }

        return $definitions;
    }

    // Marcar "Intérprete de libras" ou "Audiodescrição" marca o campo do core; desmarcar volta a não informado.
    static function syncCore(\MapasCulturais\Entities\Event $event): void
    {
        $checked = (array) $event->{self::ACCESSIBILITY};

        foreach (self::CORE_ACCESSIBILITY as $item => $key) {
            $value = in_array($item, $checked, true) ? 'Sim' : '';

            if ((string) $event->$key !== $value) {
                $event->$key = $value;
            }
        }
    }

    // O aceite guarda a data e a hora em que foi dado, pelo relógio do servidor: qualquer valor diferente do gravado
    // vira a hora de agora.
    static function stampConsent(\MapasCulturais\Entities\Event $event): void
    {
        $consent = trim((string) $event->{self::CONSENT});
        $stored = $event->id
            ? App::i()->conn->fetchOne('SELECT value FROM event_meta WHERE object_id = :id AND key = :key', ['id' => $event->id, 'key' => self::CONSENT])
            : false;

        if ($consent !== '' && $consent !== $stored) {
            $event->{self::CONSENT} = date('Y-m-d H:i:s');
        }
    }

    // A primeira publicação guarda a data, uma vez só.
    static function markFirstPublished(\MapasCulturais\Entities\Event $event): void
    {
        if ($event->status === \MapasCulturais\Entities\Event::STATUS_ENABLED && !$event->{self::FIRST_PUBLISHED}) {
            $event->{self::FIRST_PUBLISHED} = date('Y-m-d H:i:s');
        }
    }

    // A instituição e o CNPJ só valem para quem realiza com eles (itens 25.1 a 27): mudar o tipo apaga o resto.
    static function clearInstitution(\MapasCulturais\Entities\Event $event): void
    {
        $producer = $event->{self::PRODUCER};
        $keys = in_array($producer, self::WITH_INSTITUTION, true)
            ? (in_array($producer, self::WITH_CNPJ, true) ? [] : [self::CNPJ])
            : [self::INSTITUTION, self::CNPJ, self::INSTITUTION_IS_POINT];

        foreach ($keys as $key) {
            if ((string) $event->$key !== '') {
                $event->$key = '';
            }
        }
    }

    /** @return list<int> os ids das mestras e dos mestres ligados ao festejo */
    static function masters(\MapasCulturais\Entities\Event $event): array
    {
        return array_values(array_filter(array_map('intval', explode('|', (string) $event->{self::MASTERS}))));
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
