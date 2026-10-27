<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventSealRelation;
use MapasCulturais\Entities\Seal;

/**
 * Selo dos festejos ("Festejo do Calendário de Festejos Populares do Brasil"): o festejo publicado leva o selo, que dá
 * a página de certificado do core; despublicado, perde. O id vem de MDC_FESTIVITY_SEAL_ID.
 */
class FestivitySeal
{
    const CONFIG_KEY = 'mdc.festivitySealId';

    private bool $resolved = false;

    private ?Seal $seal = null;

    function get(): ?Seal
    {
        if ($this->resolved) {
            return $this->seal;
        }

        $this->resolved = true;

        $app = App::i();
        $id = $this->configuredId();

        if (!$id) {
            $app->log->error('MDC: o selo dos festejos não está configurado. Defina MDC_FESTIVITY_SEAL_ID no ambiente.');

            return null;
        }

        $seal = $app->repo('Seal')->find($id);

        if (!$seal) {
            $app->log->error("MDC: o selo {$id} não existe nesta instalação. Confira MDC_FESTIVITY_SEAL_ID.");

            return null;
        }

        return $this->seal = $seal;
    }

    /**
     * Publicado, com o selo; fora disso (rascunho, arquivado, lixeira), sem. O certificado conta desde a primeira
     * publicação: publicar de novo mantém a data (FestivityFields::FIRST_PUBLISHED). Emitido em nome do dono do selo.
     */
    function sync(Event $event): void
    {
        $seal = $this->get();

        if (!$seal) {
            return;
        }

        $published = $event->status === Event::STATUS_ENABLED;
        $relation = $this->relationOf($event);

        if ($published === (bool) $relation) {
            return;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            if ($published) {
                $relation = $event->createSealRelation($seal, false, false, $seal->owner);
                $since = $event->{FestivityFields::FIRST_PUBLISHED};
                if ($since) {
                    $relation->createTimestamp = new \DateTime($since);
                }
                $relation->save(true);
            } else {
                $event->removeSealRelation($seal);
            }
        } finally {
            $app->enableAccessControl();
        }
    }

    function relationOf(Event $event): ?EventSealRelation
    {
        $seal = $this->get();

        return $seal && $event->id ? App::i()->repo('EventSealRelation')->findOneBy(['seal' => $seal, 'owner' => $event]) : null;
    }

    // Só a chave: get() avisa no log quando falta, e há quem pergunte a cada consulta.
    function configuredId(): ?int
    {
        return ((int) (App::i()->config[self::CONFIG_KEY] ?? 0)) ?: null;
    }

    function isTheFestivitySeal(Seal $seal): bool
    {
        return $this->configuredId() === $seal->id;
    }
}
