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

    use ResolvesConfiguredEntity;

    function get(): ?Seal
    {
        return $this->resolveConfigured(
            self::CONFIG_KEY,
            'Seal',
            'MDC: o selo dos festejos não está configurado. Defina MDC_FESTIVITY_SEAL_ID no ambiente.',
            'MDC: o selo %d não existe nesta instalação. Confira MDC_FESTIVITY_SEAL_ID.'
        );
    }

    /**
     * Publicado e com as 6 etapas, com o selo; fora disso, sem. O certificado conta desde a primeira publicação
     * (FestivityFields::FIRST_PUBLISHED) e é emitido em nome do dono do selo.
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

        // O selo só entra com as 6 etapas; a retirada não depende delas.
        if ($published && FestivityForm::missing($event)) {
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

    // O contador da home: festejos publicados, que têm o selo. Sem o selo configurado, zero e sem registrar erro.
    function countPublished(): int
    {
        if (!$this->configuredId()) {
            return 0;
        }

        return (int) App::i()->conn->fetchOne(
            "SELECT count(*) FROM seal_relation r JOIN event e ON e.id = r.object_id
             WHERE r.seal_id = :seal AND r.object_type = :type AND e.status = :enabled",
            ['seal' => $this->configuredId(), 'type' => Event::class, 'enabled' => Event::STATUS_ENABLED]
        );
    }

    function isTheFestivitySeal(Seal $seal): bool
    {
        return $this->configuredId() === $seal->id;
    }
}
