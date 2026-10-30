<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Dados públicos do programa no agente da mestra aprovada (nome artístico, UF, cidade e atuação), para o perfil e a
 * busca. Vêm do cadastro: entram na aprovação e saem, com o selo, quando ele deixa de estar aprovado.
 */
class MasterPublicData
{
    const KNOWN_AS = 'mdcNomeArtistico';

    const UF = 'mdcUf';

    const CITY = 'mdcCidade';

    // As atividades, como no cabeçalho do perfil ("Bilros, Coco").
    const ACTIVITIES = 'mdcAtividades';

    // Os itens da árvore marcados, como "|1|4|5|": o filtro da busca procura "|4|".
    const ACTING = 'mdcAtuacao';

    /** @return array<string, array> a definição de cada metadado, públicos */
    static function metadata(): array
    {
        return [
            self::KNOWN_AS => ['label' => i::__('Nome artístico na Rede'), 'type' => 'string'],
            self::UF => ['label' => i::__('UF na Rede'), 'type' => 'string'],
            self::CITY => ['label' => i::__('Cidade na Rede'), 'type' => 'string'],
            self::ACTIVITIES => ['label' => i::__('Atividades na Rede'), 'type' => 'string'],
            self::ACTING => ['label' => i::__('Itens da atuação na Rede'), 'type' => 'string'],
        ];
    }

    function publish(Registration $registration): void
    {
        $app = App::i();
        $agent = $registration->owner;

        $app->disableAccessControl();

        try {
            $registration->opportunity->registerRegistrationMetadata();
            $acting = MasterProfile::actingOf($registration);
            $city = MasterProfile::cityOf($registration);
            $ids = array_column($acting['nodes'] ?? [], 'id');

            $agent->{self::KNOWN_AS} = MasterProfile::knownAsOf($registration);
            $agent->{self::CITY} = $city;
            $agent->{self::UF} = preg_match('/,\s*([A-Z]{2})\s*$/', $city, $match) ? $match[1] : '';
            $agent->{self::ACTIVITIES} = implode(', ', MasterProfile::activities($acting ?? []));
            $agent->{self::ACTING} = $ids ? '|' . implode('|', $ids) . '|' : '';
            $agent->save(true);
        } finally {
            $app->enableAccessControl();
        }
    }

    // Apaga os dados públicos do agente; sem dado publicado, não grava.
    function clear(Agent $agent): void
    {
        if (!array_filter(array_map(fn ($key) => $agent->$key, array_keys(self::metadata())))) {
            return;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            foreach (array_keys(self::metadata()) as $key) {
                $agent->$key = '';
            }
            $agent->save(true);
        } finally {
            $app->enableAccessControl();
        }
    }
}
