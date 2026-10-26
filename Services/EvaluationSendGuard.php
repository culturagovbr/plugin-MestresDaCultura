<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use MapasCulturais\UserInterface;

/**
 * Antes de cada gravação, lê no banco o status da avaliação: já enviada, recusa um novo envio e qualquer gravação de
 * quem a carregou antes do envio. Quem a carregou já enviada segue. Só leituras, sem transação aberta.
 */
class EvaluationSendGuard
{
    static function message(): string
    {
        return i::__('Esta moderação já foi enviada.');
    }

    // Está sendo enviada quando a data de envio muda nesta gravação.
    static function isSending(RegistrationEvaluation $evaluation): bool
    {
        if ($evaluation->status !== RegistrationEvaluation::STATUS_SENT) {
            return false;
        }

        $stored = App::i()->em->getUnitOfWork()->getOriginalEntityData($evaluation);

        return ($stored['sentTimestamp'] ?? null) !== $evaluation->sentTimestamp;
    }

    /**
     * Se o parecer do moderador já foi enviado, lido no banco.
     */
    static function isSentBy(int $registrationId, int $userId): bool
    {
        return (int) App::i()->conn->fetchOne('SELECT status FROM registration_evaluation WHERE registration_id = :registration AND user_id = :user', ['registration' => $registrationId, 'user' => $userId]) === RegistrationEvaluation::STATUS_SENT;
    }

    // Antes da gravação.
    function check(RegistrationEvaluation $evaluation): void
    {
        $app = App::i();
        $status = (int) $app->conn->fetchOne('SELECT status FROM registration_evaluation WHERE id = :id', ['id' => $evaluation->id]);
        $loaded = (int) ($app->em->getUnitOfWork()->getOriginalEntityData($evaluation)['status'] ?? -1);

        if ($status === RegistrationEvaluation::STATUS_SENT && (self::isSending($evaluation) || $loaded !== RegistrationEvaluation::STATUS_SENT)) {
            throw new PermissionDenied($app->user, $evaluation, 'send', self::message());
        }
    }

    // Antes do envio em lote, recarrega do banco os pareceres do moderador já carregados nesta requisição.
    function refreshBatch(Opportunity $opportunity, UserInterface $user): void
    {
        $app = App::i();
        $ids = $app->conn->fetchFirstColumn('
            SELECT e.id FROM registration_evaluation e JOIN registration r ON r.id = e.registration_id
            WHERE r.opportunity_id = :opportunity AND e.user_id = :user', ['opportunity' => $opportunity->id, 'user' => $user->id]);

        foreach ($ids as $id) {
            if ($evaluation = $app->em->getUnitOfWork()->tryGetById($id, RegistrationEvaluation::class)) {
                $app->em->refresh($evaluation);
            }
        }
    }
}
