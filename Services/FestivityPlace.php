<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;

/**
 * O local do festejo é um Espaço do core, que cada ocorrência da agenda pede. No subsite dos Mestres, os espaços
 * seguem sem busca, painel nem página própria; quem tem conta grava só o próprio local, pelas rotas do formulário.
 */
class FestivityPlace
{
    // O tipo do local; o padrão é "Outros (informar qual)", dos tipos do SNIIC (plugin SNIICDataStandard).
    const CONFIG_KEY = 'mdc.festivityPlaceType';

    const TYPE = 2040;

    // O "Outros (informar qual)" do SNIIC pede o nome do tipo, neste campo; ele só existe com o SNIIC.
    const OTHER_TYPE_FIELD = 'informarQualOutroTipoDeEspaco';

    // [método, ação] de espaço que ficam abertos.
    const WRITES = [['POST', 'index'], ['PATCH', 'single'], ['PUT', 'single']];

    static function type(): int
    {
        return (int) (App::i()->config[self::CONFIG_KEY] ?? self::TYPE);
    }

    static function isWrite(string $method, string $action): bool
    {
        return in_array([strtoupper($method), $action], self::WRITES, true);
    }

    /**
     * A UF, a cidade, a região e o resumo da agenda vêm do primeiro período, pela data (itens 10 a 13; no festejo
     * itinerante, o primeiro). O core salva o evento a cada período gravado ou apagado (EventOccurrence::save e
     * delete); o plugin chama isto antes de salvar o festejo e ao mudar o endereço de um local.
     */
    static function sync(Event $event): void
    {
        if (!$event->id) {
            return;
        }

        $first = App::i()->em->createQuery('SELECT o FROM ' . EventOccurrence::class . ' o WHERE o.event = :event AND o.status > 0 ORDER BY o.startsOn ASC, o.id ASC')
            ->setParameter('event', $event)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        $uf = (string) $first?->space?->En_Estado;
        $values = [
            FestivityFields::UF => $uf,
            FestivityFields::CITY => (string) $first?->space?->En_Municipio,
            FestivityFields::REGION => FestivityFields::region($uf),
            // Para os cartões da busca: o texto do período, ou a data dele.
            FestivityFields::AGENDA_SUMMARY => $first ? ((string) $first->description ?: $first->startsOn?->format('d/m/Y') ?? '') : '',
        ];

        foreach ($values as $key => $value) {
            if ((string) $event->$key !== $value) {
                $event->$key = $value;
            }
        }
    }

    /** @return list<Event> os eventos com algum período no local */
    static function eventsAt(int $spaceId): array
    {
        return App::i()->em->createQuery('SELECT e FROM ' . Event::class . ' e WHERE e.id IN (SELECT IDENTITY(o.event) FROM ' . EventOccurrence::class . ' o WHERE IDENTITY(o.space) = :space)')
            ->setParameter('space', $spaceId)
            ->getResult();
    }
}
