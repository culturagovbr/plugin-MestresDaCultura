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
                FestivityFields::TERRITORY => i::__('Informe o território'),
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

    private static function isEmpty(Event $event, string $key): bool
    {
        return match ($key) {
            'occurrences' => count($event->occurrences ?? []) === 0,
            // O responsável é quem cadastra: o nome completo vem do perfil.
            'responsible' => trim((string) $event->owner?->nomeCompleto) === '',
            default => in_array($event->$key, [null, '', []], true),
        };
    }
}
