<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;

/**
 * A página pública do festejo, sobre a página de evento do core: o que o cadastro do programa guarda no festejo, só o
 * que é público. Da pessoa responsável, só o e-mail e o telefone que ela autorizou.
 */
class FestivityProfile
{
    function __construct(private FestivitySeal $festivitySeal, private ProgramSeal $programSeal)
    {
    }

    function of(Event $event): ?array
    {
        if (!$event->id) {
            return null;
        }

        $definitions = FestivityFields::definitions();
        $relation = $this->festivitySeal->relationOf($event);
        $since = $event->{FestivityFields::FIRST_PUBLISHED};
        $law = $event->{FestivityFields::LAW} === 'Sim';

        return [
            'segment' => (string) $event->{FestivityFields::SEGMENT},
            'typologies' => $this->withOther((array) $event->{FestivityFields::TYPOLOGIES}, $event->{FestivityFields::OTHER_TYPOLOGY}),
            'size' => $definitions[FestivityFields::SIZE]['options'][$event->{FestivityFields::SIZE}] ?? '',
            'firstYear' => $event->{FestivityFields::FIRST_YEAR},
            'editions' => $event->{FestivityFields::EDITIONS},
            'usualPeriod' => (string) $event->{FestivityFields::USUAL_PERIOD},
            'venue' => (string) $event->{FestivityFields::VENUE},
            'territory' => (string) $event->{FestivityFields::TERRITORY},
            'place' => implode(', ', array_filter([$event->{FestivityFields::CITY}, $event->{FestivityFields::UF}])),
            'region' => (string) $event->{FestivityFields::REGION},
            'certificateUrl' => $relation ? App::i()->createUrl('seal', 'sealRelation', [$relation->id]) : null,
            'inCalendarSince' => $since ? date('d/m/Y', strtotime($since)) : null,
            'masters' => $this->masters($event),
            'economy' => $this->economy((array) $event->{FestivityFields::ECONOMY}),
            'economyOther' => (string) $event->{FestivityFields::OTHER_ECONOMY},
            'accessibility' => $this->withOther((array) $event->{FestivityFields::ACCESSIBILITY}, $event->{FestivityFields::OTHER_ACCESSIBILITY}),
            'shelter' => $this->withOther((array) $event->{FestivityFields::SHELTER}, $event->{FestivityFields::OTHER_SHELTER}),
            'culturePoint' => $event->{FestivityFields::CULTURE_POINT} === 'Sim' ? (string) $event->{FestivityFields::CULTURE_POINT_NAME} : '',
            'producer' => (string) $event->{FestivityFields::PRODUCER},
            'institution' => (string) $event->{FestivityFields::INSTITUTION},
            'institutionIsPoint' => $event->{FestivityFields::INSTITUTION_IS_POINT} === 'Sim',
            // Os campos públicos do perfil: só existem com a autorização (mdc-festivity-person).
            'email' => (string) $event->owner?->emailPublico,
            'phone' => (string) $event->owner?->telefonePublico,
            'law' => $law,
            'lawUrl' => $law ? $event->getFile(FestivityFields::LAW_FILE)?->url : null,
            'funding' => $this->funding($event),
        ];
    }

    /** @return list<string> */
    private function withOther(array $items, ?string $other): array
    {
        return array_values(array_filter([...$items, trim((string) $other)]));
    }

    /**
     * Só quem ainda tem o selo do programa: a mestra pode sair da Rede depois de ligada ao festejo.
     *
     * @return list<array{id: int, name: string, city: string, avatar: ?string, url: string}>
     */
    private function masters(Event $event): array
    {
        $masters = [];

        foreach (FestivityFields::masters($event) as $id) {
            $agent = $this->programSeal->isGivenTo($id) ? App::i()->repo(Agent::class)->find($id) : null;

            if ($agent) {
                $masters[] = [
                    'id' => $agent->id,
                    'name' => (string) ($agent->{MasterPublicData::KNOWN_AS} ?: $agent->name),
                    'city' => (string) $agent->{MasterPublicData::CITY},
                    'avatar' => $agent->avatar?->transform('avatarSmall')?->url,
                    'url' => $agent->singleUrl,
                ];
            }
        }

        return $masters;
    }

    /** @return list<array{segment: string, items: list<string>}> "Música: DJ" agrupado por segmento */
    private function economy(array $values): array
    {
        $groups = [];

        foreach ($values as $value) {
            [$segment, $occupation] = array_pad(explode(': ', $value, 2), 2, '');
            $groups[$segment][] = $occupation;
        }

        return array_map(fn ($segment) => ['segment' => $segment, 'items' => $groups[$segment]], array_keys($groups));
    }

    /** @return list<array{label: string, years: string}> as fontes com "Sim" */
    private function funding(Event $event): array
    {
        $funding = [];

        foreach (FestivityFields::fundingSources() as $key => $label) {
            if ($event->$key === 'Sim') {
                $funding[] = ['label' => $label, 'years' => (string) $event->{"{$key}Anos"}];
            }
        }

        return $funding;
    }
}
