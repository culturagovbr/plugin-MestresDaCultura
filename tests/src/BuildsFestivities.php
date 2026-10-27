<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\User;
use MDC\Services\FestivityPlace;

/**
 * O festejo nos testes da fase: o rascunho, um local e um período da agenda, como o formulário grava. Quem usa precisa
 * do UserDirector.
 */
trait BuildsFestivities
{
    private function draft(?User $user = null): Event
    {
        $user ??= $this->userDirector->createUser();
        $this->login($user);
        $event = new Event;
        $event->name = 'Samba de Roda de Santa Bárbara';
        $event->owner = $user->profile;
        $event->status = Event::STATUS_DRAFT;
        $event->save(true);

        return $event;
    }

    private function place(Event $event, string $city = 'Cachoeira', string $uf = 'BA'): Space
    {
        $space = new Space;
        $space->name = "Praça de {$city}";
        $space->owner = $event->owner;
        $space->type = FestivityPlace::type();
        $space->En_Municipio = $city;
        $space->En_Estado = $uf;
        $space->save(true);

        return $space;
    }

    private function period(Event $event, Space $space, string $startsOn = '2026-12-03'): EventOccurrence
    {
        $occurrence = new EventOccurrence;
        $occurrence->event = $event;
        $occurrence->space = $space;
        $occurrence->rule = ['startsOn' => $startsOn, 'startsAt' => '18:00', 'duration' => 300, 'frequency' => 'once'];
        $occurrence->save(true);

        return $occurrence;
    }
}
