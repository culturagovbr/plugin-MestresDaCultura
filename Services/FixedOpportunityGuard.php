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

    /** Os campos do fluxo contínuo, que mudam as datas finais das fases. */
    private const FLOW = ['hasEndDate', 'isContinuousFlow'];

    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    function locks(?Opportunity $opportunity, ?UserInterface $user): bool
    {
        return $user && !$user->is('saasSuperAdmin') && $this->fixedOpportunity->isConfigured() && $this->fixedOpportunity->matches($opportunity);
    }

    /**
     * As datas (e o fluxo contínuo, que decide se há data final) que mudaram em relação ao que está gravado,
     * comparadas ao minuto.
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
        $changed = array_filter($properties, fn ($property) => self::minute($stored[$property] ?? null) !== self::minute($entity->$property));

        // Os metadados do fluxo, comparados com o banco.
        if ($entity instanceof Opportunity) {
            $stored = App::i()->conn->fetchAllKeyValue(
                'SELECT key, value FROM opportunity_meta WHERE object_id = :id AND key IN (:keys)',
                ['id' => $entity->id, 'keys' => self::FLOW],
                ['keys' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $changed = [...$changed, ...array_filter(self::FLOW, fn ($key) => self::flag($stored[$key] ?? null) !== self::flag($entity->$key))];
        }

        return array_values($changed);
    }

    // Lê como booleano o texto gravado ("1", "") e o valor da tela; vazio vale falso.
    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function minute(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d H:i');
    }
}
