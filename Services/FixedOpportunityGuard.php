<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\UserInterface;

/**
 * Só o saasSuperAdmin altera a estrutura da oportunidade do programa (ela, as fases e o formulário), e só ele a
 * arquiva, exclui ou duplica. A recusa é no servidor.
 */
class FixedOpportunityGuard
{
    private const DATES = ['registrationFrom', 'registrationTo', 'publishTimestamp'];

    private const EVALUATION_DATES = ['evaluationFrom', 'evaluationTo'];

    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    function locks(?Opportunity $opportunity, ?UserInterface $user): bool
    {
        return $user && !$user->is('saasSuperAdmin') && $this->fixedOpportunity->isConfigured() && $this->fixedOpportunity->matches($opportunity);
    }

    /**
     * As datas que mudaram em relação ao que está gravado. Ao minuto: a tela não manda os segundos, e reenviar a
     * mesma data não é mudança.
     *
     * @return string[]
     */
    function changedDates(Opportunity|EvaluationMethodConfiguration $entity): array
    {
        if (!$entity->id) {
            return [];
        }

        $stored = App::i()->em->getUnitOfWork()->getOriginalEntityData($entity);
        $properties = $entity instanceof Opportunity ? self::DATES : self::EVALUATION_DATES;

        return array_values(array_filter($properties, fn ($property) => self::minute($stored[$property] ?? null) !== self::minute($entity->$property)));
    }

    private static function minute(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d H:i');
    }
}
