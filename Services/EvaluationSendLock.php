<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

/**
 * Clique duplo em "Enviar", ou duas abas, mandam a mesma avaliação duas vezes. O core recusa o envio de quem carregou
 * a avaliação já enviada, mas duas requisições que a carregaram antes de qualquer uma terminar enviam as duas, e a
 * devolução, o e-mail e a notificação saem em dobro. Ao enviar, a linha da avaliação fica travada até o fim da
 * gravação e o status é lido de novo, depois da trava: já enviada, o envio é recusado antes de qualquer efeito.
 */
class EvaluationSendLock
{
    /** @var int[] as avaliações travadas, da mais antiga à mais recente: um envio pode acontecer dentro de outro */
    private array $locked = [];

    // O core marca o envio numa propriedade privada; o que muda de fato ao enviar é a data de envio.
    static function isSending(RegistrationEvaluation $evaluation): bool
    {
        if (!$evaluation->id || $evaluation->status !== RegistrationEvaluation::STATUS_SENT) {
            return false;
        }

        $stored = App::i()->em->getUnitOfWork()->getOriginalEntityData($evaluation);

        return ($stored['sentTimestamp'] ?? null) !== $evaluation->sentTimestamp;
    }

    function claim(RegistrationEvaluation $evaluation): void
    {
        $app = App::i();
        $conn = $app->em->getConnection();
        $conn->beginTransaction();

        // Com a linha travada, a leitura vê o envio que a outra requisição já gravou.
        $status = (int) $conn->fetchOne('SELECT status FROM registration_evaluation WHERE id = :id FOR UPDATE', ['id' => $evaluation->id]);

        if ($status === RegistrationEvaluation::STATUS_SENT) {
            $conn->rollBack();

            throw new PermissionDenied($app->user, $evaluation, 'send', i::__('Esta avaliação já foi enviada.'));
        }

        $this->locked[] = $evaluation->id;
    }

    // Depois da gravação. Se ela falhar no meio, a transação fica aberta e cai com a conexão, ao fim da requisição.
    function release(RegistrationEvaluation $evaluation): void
    {
        if ($this->locked && end($this->locked) === $evaluation->id) {
            array_pop($this->locked);
            App::i()->em->getConnection()->commit();
        }
    }
}
