<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\i;

/**
 * O cadastro do festejo em 6 etapas e o que cada uma cobra. O rascunho salva sem cobrar nada; "Salvar e avançar"
 * confere a etapa (Controller::GET_festivityCheck), e a publicação confere todas. Os erros saem campo => mensagens.
 */
class FestivityForm
{
    // Os campos da pessoa responsável, no perfil dela: os mesmos do formulário dos Mestres.
    const PERSON_FIELDS = [
        'nomeCompleto', 'dataDeNascimento', 'emailPrivado', 'emailPublico', 'telefone1', 'telefonePublico',
        'genero', 'orientacaoSexual', 'raca', 'pessoaDeficiente', 'comunidadesTradicional',
    ];

    /**
     * A pessoa responsável para o formulário (mdc-festivity-person): se quem edita pode mudar o perfil do dono, se é
     * o próprio dono e, só para quem pode editar, os dados. Decidido aqui, e não pela API, que lê as permissões do
     * cache e pode negar o próprio perfil quando ele está velho.
     */
    static function person(Event $event): array
    {
        $owner = $event->owner;
        $canEdit = $owner->canUser('modify');
        $person = ['id' => $owner->id, 'canEdit' => $canEdit, 'isOwn' => $owner->id === App::i()->user->profile?->id, 'data' => null];

        if ($canEdit) {
            foreach (self::PERSON_FIELDS as $key) {
                $value = $owner->$key;
                $person['data'][$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
        }

        return $person;
    }

    /** @return list<array{slug: string, label: string}> */
    static function steps(): array
    {
        return [
            ['slug' => 'apresentacao', 'label' => i::__('Apresentação do Festejo')],
            ['slug' => 'localizacao', 'label' => i::__('Localização')],
            ['slug' => 'impacto', 'label' => i::__('Impacto Cultural, Social e Econômico')],
            ['slug' => 'realizador', 'label' => i::__('Sobre o Realizador')],
            ['slug' => 'marcadores', 'label' => i::__('Marcadores Sociais')],
            ['slug' => 'lgpd', 'label' => i::__('Apresentação e LGPD')],
        ];
    }

    /**
     * As listas da etapa 3 no formato do formulário (mdc-festivity-checklist): grupos com os itens. Na economia, o
     * valor leva o segmento ("Música: DJ").
     *
     * @return array<string, list<array{label: string, items: list<array{value: string, label: string}>}>>
     */
    static function lists(): array
    {
        $group = fn (string $label, array $items, ?string $prefix = null) => [
            'label' => $label,
            'items' => array_map(fn ($item) => ['value' => $prefix ? "{$prefix}: {$item}" : $item, 'label' => $item], $items),
        ];

        return [
            'economy' => array_map(fn ($segment) => $group($segment['segmento'], $segment['ocupacoes'], $segment['segmento']), FestivityFields::economy()),
            'accessibility' => array_map(fn ($type) => $group($type['tipo'], $type['requisitos']), FestivityFields::accessibility()),
            'shelter' => [$group('', FestivityFields::shelter())],
        ];
    }

    /** @return list<array{key: string, question: string}> as fontes de recursos públicos, com a pergunta da planilha */
    static function funding(): array
    {
        $questions = [
            'mdcFestejoRouanet' => i::__('O festejo já foi realizado com recursos captados pela Lei Rouanet?'),
            'mdcFestejoPnab' => i::__('O festejo já foi realizado com recursos da Política Nacional Aldir Blanc de Fomento à Cultura (PNAB)?'),
            'mdcFestejoIncentivoLocal' => i::__('O festejo já foi realizado com recursos das Leis de Incentivo à Cultura dos Estados, do DF ou dos Municípios?'),
            'mdcFestejoOutrasFontes' => i::__('O festejo já foi realizado com outras fontes de recursos públicos, inclusive emenda parlamentar?'),
        ];

        return array_map(fn ($key) => ['key' => $key, 'question' => $questions[$key]], array_keys(FestivityFields::fundingSources()));
    }

    /** @return array<int, list<string>> etapa => campos que ela cobra, para o formulário marcar "obrigatório" */
    static function requiredKeys(): array
    {
        return array_map('array_keys', self::required());
    }

    /**
     * Os campos que faltam numa etapa (0 a 5) ou, sem ela, em todas.
     *
     * @return array<string, list<string>>
     */
    static function missing(Event $event, ?int $step = null): array
    {
        $required = self::required();
        $steps = $step === null ? array_keys($required) : [$step];
        $missing = [];

        foreach ($steps as $index) {
            foreach ($required[$index] ?? [] as $key => $message) {
                if (self::isEmpty($event, $key)) {
                    $missing[$key] = [$message];
                }
            }
        }

        return $missing;
    }

    /** @return array<int, array<string, string>> etapa => campo => mensagem */
    private static function required(): array
    {
        return [
            0 => [
                'name' => i::__('Informe o nome do festejo'),
                'longDescription' => i::__('Conte sobre o festejo'),
                FestivityFields::SEGMENT => i::__('Escolha o segmento'),
                FestivityFields::TYPOLOGIES => i::__('Escolha ao menos uma tipologia'),
                FestivityFields::SIZE => i::__('Informe o porte'),
                FestivityFields::VENUE => i::__('Informe onde o festejo é realizado'),
            ],
            1 => [
                'occurrences' => i::__('Informe ao menos um período, com o local'),
                'place' => i::__('Informe o estado e o município do local'),
                // Um período com o local excluído.
                'removedPlace' => i::__('O local de um período foi excluído: remova o período e cadastre outro'),
                FestivityFields::TERRITORY => i::__('Informe o território'),
                FestivityFields::LAW_FILE => i::__('Envie o arquivo da lei que reconhece o festejo'),
            ],
            2 => [
                FestivityFields::CULTURE_POINT_NAME => i::__('Informe o nome do Ponto ou Pontão de Cultura'),
                FestivityFields::ACCESSIBILITY => i::__('Marque os recursos de acessibilidade'),
                FestivityFields::SHELTER => i::__('Marque os espaços de acolhimento'),
            ] + array_combine(
                array_map(fn ($key) => "{$key}Anos", array_keys(FestivityFields::fundingSources())),
                array_map(fn ($label) => sprintf(i::__('Informe os anos com recursos de: %s'), $label), FestivityFields::fundingSources())
            ),
            3 => [
                FestivityFields::PRODUCER => i::__('Informe o tipo de realizador'),
                FestivityFields::INSTITUTION => i::__('Informe o nome da instituição, do grupo ou do órgão'),
                FestivityFields::CNPJ => i::__('Informe o CNPJ'),
                'responsible' => i::__('Informe o nome do responsável pelo festejo'),
            ],
            5 => [
                FestivityFields::CONSENT => i::__('É preciso autorizar a publicação dos dados'),
            ],
        ];
    }

    /**
     * O que está errado mesmo no rascunho: a classificação é um segmento e até duas tipologias dele, e o "Outro" conta
     * como uma.
     *
     * @return array<string, list<string>>
     */
    static function invalid(Event $event): array
    {
        $segment = $event->{FestivityFields::SEGMENT};
        $typologies = (array) $event->{FestivityFields::TYPOLOGIES};
        $ofSegment = array_column(FestivityFields::classification(), 'tipologias', 'segmento')[$segment] ?? [];
        $count = count($typologies) + (self::filled($event->{FestivityFields::OTHER_TYPOLOGY}) ? 1 : 0);

        $invalid = [];

        if ($count > 2 || array_diff($typologies, $ofSegment)) {
            $invalid[FestivityFields::TYPOLOGIES] = [i::__('Escolha até duas tipologias do segmento')];
        }

        // Os anos de cada fonte de recursos: quatro dígitos, separados por vírgula, ponto e vírgula ou "e", e nenhum
        // depois do ano atual.
        foreach (array_keys(FestivityFields::fundingSources()) as $key) {
            $years = trim((string) $event->{"{$key}Anos"});

            if ($years !== '' && !preg_match('/^\d{4}(\s*(,|;|e)\s*\d{4})*$/u', $years)) {
                $invalid["{$key}Anos"] = [i::__('Informe os anos com quatro dígitos, separados por vírgula. Ex.: 2023, 2024')];
            } elseif ($years !== '' && max(array_map('intval', preg_split('/\D+/', $years))) > (int) date('Y')) {
                $invalid["{$key}Anos"] = [i::__('Os anos com recursos não podem ser depois do ano atual')];
            }
        }

        // Só mestras e mestres da Rede, com o selo do programa, entre os que entram agora; os já ligados ficam.
        $stored = $event->id
            ? (string) App::i()->conn->fetchOne('SELECT value FROM event_meta WHERE object_id = :id AND key = :key', ['id' => $event->id, 'key' => FestivityFields::MASTERS])
            : '';
        $newcomers = array_values(array_diff(FestivityFields::masters($event), array_map('intval', explode('|', $stored))));

        if ($newcomers && count((new ProgramSeal)->givenAmong($newcomers)) < count($newcomers)) {
            $invalid[FestivityFields::MASTERS] = [i::__('Escolha só mestras e mestres da Rede')];
        }

        return $invalid;
    }

    /**
     * O campo que só é cobrado em certa resposta: no "Sim" do anterior, o nome do Ponto (item 18.1), as listas de
     * acessibilidade e de acolhimento (19 e 20) e os anos de cada fonte (21.1 a 24.1); pelo tipo de realizador, o
     * nome da instituição e o CNPJ (25.1 e 26).
     *
     * @return array<string, callable(Event): bool>
     */
    private static function conditions(): array
    {
        $yes = fn (string $parent) => fn (Event $event) => $event->$parent === 'Sim';
        $producerIn = fn (array $producers) => fn (Event $event) => in_array($event->{FestivityFields::PRODUCER}, $producers, true);

        $conditions = [
            FestivityFields::CULTURE_POINT_NAME => $yes(FestivityFields::CULTURE_POINT),
            FestivityFields::ACCESSIBILITY => $yes(FestivityFields::HAS_ACCESSIBILITY),
            FestivityFields::SHELTER => $yes(FestivityFields::HAS_SHELTER),
            FestivityFields::INSTITUTION => $producerIn(FestivityFields::WITH_INSTITUTION),
            FestivityFields::CNPJ => $producerIn(FestivityFields::WITH_CNPJ),
        ];

        foreach (array_keys(FestivityFields::fundingSources()) as $key) {
            $conditions["{$key}Anos"] = $yes($key);
        }

        return $conditions;
    }

    private static function isEmpty(Event $event, string $key): bool
    {
        if (($applies = self::conditions()[$key] ?? null) && !$applies($event)) {
            return false;
        }

        return match ($key) {
            'occurrences' => count($event->occurrences ?? []) === 0,
            // Vêm do primeiro local da agenda (FestivityPlace::sync); sem período, só a falta dele aparece.
            'place' => count($event->occurrences ?? []) > 0
                && (!self::filled($event->{FestivityFields::UF}) || !self::filled($event->{FestivityFields::CITY})),
            'removedPlace' => $event->id && App::i()->em->createQuery('SELECT COUNT(o) FROM ' . EventOccurrence::class . ' o JOIN o.space s WHERE o.event = :event AND o.status > 0 AND s.status < 0')
                ->setParameter('event', $event)
                ->getSingleScalarResult() > 0,
            // A lista ou o "Outro" dela.
            FestivityFields::TYPOLOGIES, FestivityFields::ACCESSIBILITY, FestivityFields::SHELTER
                => !self::filled($event->$key) && !self::filled($event->{FestivityFields::OTHERS[$key]}),
            // O arquivo só quando há lei própria.
            FestivityFields::LAW_FILE => $event->{FestivityFields::LAW} === 'Sim' && !$event->getFile(FestivityFields::LAW_FILE),
            // O nome completo do perfil do dono, lido com os privados; daqui só sai se está preenchido.
            'responsible' => !self::filled($event->owner?->getMetadata('nomeCompleto')),
            default => !self::filled($event->$key),
        };
    }

    private static function filled(mixed $value): bool
    {
        return !in_array(is_string($value) ? trim($value) : $value, [null, '', []], true);
    }
}
