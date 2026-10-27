<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\User;
use MDC\Services\FestivityPlace;
use MDC\Services\FestivitySeal;
use Tests\Builders\SealBuilder;

/**
 * O festejo nos testes da fase: o selo dos festejos configurado, o rascunho, um local e um período da agenda, como o
 * formulário grava. Quem usa precisa do UserDirector.
 */
trait BuildsFestivities
{
    private ?Seal $festivitySeal = null;

    // Cria e configura o selo dos festejos na primeira chamada, dentro da transação do teste.
    private function festivitySeal(): Seal
    {
        if (!$this->festivitySeal) {
            $admin = $this->userDirector->createUser('admin');
            $this->login($admin);
            $this->festivitySeal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
            $this->app->config[FestivitySeal::CONFIG_KEY] = $this->festivitySeal->id;
            $this->logout();
        }

        return $this->festivitySeal;
    }

    private function draft(?User $user = null): Event
    {
        $this->festivitySeal();
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
