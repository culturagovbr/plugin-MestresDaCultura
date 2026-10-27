<?php

namespace MDC\Services;

use MapasCulturais\Entities\Event;
use MapasCulturais\i;

/**
 * O cadastro do festejo em 6 etapas, como o protótipo oficial (decisão FJD2), e o que cada uma cobra. O rascunho salva
 * sem cobrar nada; "Salvar e avançar" confere a etapa (Controller::GET_festivityCheck), e a publicação confere todas,
 * também pelo Publicar do painel (Plugin::openTheFestivityForm). Os erros saem no formato do core, campo => mensagens.
 */
class FestivityForm
{
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
                FestivityFields::TERRITORY => i::__('Informe o território'),
                FestivityFields::LAW_FILE => i::__('Envie o arquivo da lei que reconhece o festejo'),
            ],
            3 => [
                FestivityFields::PRODUCER => i::__('Informe o tipo de realizador'),
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

        if ($count > 2 || array_diff($typologies, $ofSegment)) {
            return [FestivityFields::TYPOLOGIES => [i::__('Escolha até duas tipologias do segmento')]];
        }

        return [];
    }

    private static function isEmpty(Event $event, string $key): bool
    {
        return match ($key) {
            'occurrences' => count($event->occurrences ?? []) === 0,
            // Vêm do primeiro local da agenda (FestivityPlace::sync); sem período, só a falta dele aparece.
            'place' => count($event->occurrences ?? []) > 0
                && (!self::filled($event->{FestivityFields::UF}) || !self::filled($event->{FestivityFields::CITY})),
            FestivityFields::TYPOLOGIES => !self::filled($event->{FestivityFields::TYPOLOGIES}) && !self::filled($event->{FestivityFields::OTHER_TYPOLOGY}),
            // O arquivo só quando há lei própria.
            FestivityFields::LAW_FILE => $event->{FestivityFields::LAW} === 'Sim' && !$event->getFile(FestivityFields::LAW_FILE),
            // O responsável é quem cadastra: o nome completo vem do perfil.
            'responsible' => !self::filled($event->owner?->nomeCompleto),
            default => !self::filled($event->$key),
        };
    }

    private static function filled(mixed $value): bool
    {
        return !in_array(is_string($value) ? trim($value) : $value, [null, '', []], true);
    }
}
