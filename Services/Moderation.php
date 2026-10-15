<?php

namespace MDC\Services;

use MailNotification\JobTypes\SendMailNotification;
use MapasCulturais\App;
use MapasCulturais\Entities\Notification;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MapasCulturais\i;

/**
 * Moderação do cadastro: uma pessoa da equipe marca cada campo moderado como ok ou não ok. Com algum "não ok", o
 * cadastro não é recusado: fica pendente, com os campos recusados reabertos para o mestre comprovar e reenviar.
 * Sem prazo: fica pendente até que ele atenda o campo, como combinado com a área. No reenvio, a avaliação volta a
 * quem avaliou, para uma nova análise.
 */
class Moderation
{
    // Data-limite dos campos reabertos, tão distante que não chega.
    const NO_DEADLINE = '2111-01-01 00:00:00';

    const MAIL_RETURNED = 'mdc_cadastro_pendente';

    const MAIL_RESENT = 'mdc_cadastro_reenviado';

    /**
     * Os campos que o avaliador recusou, pelo nome que a inscrição usa (`field_N` ou `rfc_N`), com o motivo.
     * A avaliação guarda só o número, e um campo e um anexo podem ter o mesmo: vale o que o avaliador via e, se
     * via os dois, o de mesmo título.
     *
     * @param array $evaluationData número => {label, evaluation, obs}
     * @param array<string,mixed> $visible nome => "true", os campos que o avaliador vê
     * @param array<string,string> $titles nome => título
     * @return array<array{name: string, label: string, obs: string}>
     */
    static function refusedFields(array $evaluationData, array $visible, array $titles): array
    {
        $refused = [];

        foreach ($evaluationData as $id => $item) {
            $item = (array) $item;

            if (($item['evaluation'] ?? '') !== 'invalid') {
                continue;
            }

            $candidates = array_values(array_filter(
                ["field_{$id}", "rfc_{$id}"],
                fn ($name) => isset($titles[$name]) && in_array($visible[$name] ?? null, [true, 'true'], true)
            ));

            if (count($candidates) > 1) {
                $candidates = array_values(array_filter($candidates, fn ($name) => $titles[$name] === ($item['label'] ?? null))) ?: $candidates;
            }

            if ($candidates) {
                $refused[] = ['name' => $candidates[0], 'label' => (string) ($item['label'] ?? $titles[$candidates[0]]), 'obs' => trim((string) ($item['obs'] ?? ''))];
            }
        }

        return $refused;
    }

    /** Devolve o cadastro ao mestre: reabre só os campos recusados e o avisa, com o motivo de cada um. */
    function returnToOwner(Registration $registration, RegistrationEvaluation $evaluation): void
    {
        $refused = self::refusedFields((array) $evaluation->evaluationData, $this->visibleFields($registration), $this->titles($registration));

        if (!$refused) {
            return;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            $registration->editableFields = array_column($refused, 'name');
            $registration->editableUntil = self::NO_DEADLINE;
            $registration->save(true);

            // Reabre a correção já reenviada, para o mestre reenviar de novo.
            if ($registration->editSentTimestamp) {
                $registration->reopenEditableFields();
            }

            $this->notifyOwner($registration, $refused);
        } finally {
            $app->enableAccessControl();
        }
    }

    /**
     * O mestre reenviou a correção: a avaliação enviada volta a rascunho, com as marcações de antes, e quem avaliou
     * é avisado. Só a desta inscrição; reabrir pela tela do gestor reabriria todas as do avaliador.
     */
    function sendBackToValuers(Registration $registration): void
    {
        $app = App::i();
        $evaluations = $app->repo('RegistrationEvaluation')->findBy(['registration' => $registration, 'status' => RegistrationEvaluation::STATUS_SENT]);

        // Altera a avaliação sem checar a permissão do mestre que reenviou.
        $app->disableAccessControl();

        try {
            foreach ($evaluations as $evaluation) {
                $evaluation->status = RegistrationEvaluation::STATUS_DRAFT;
                $evaluation->save(true);

                $this->notifyValuer($registration, $evaluation->user);
            }
        } finally {
            $app->enableAccessControl();
        }
    }

    private function notifyValuer(Registration $registration, User $valuer): void
    {
        $app = App::i();
        $link = $app->createUrl('registration', 'evaluation', [$registration->id]);

        $message = $app->renderMailerTemplate(self::MAIL_RESENT, [
            'siteName' => $app->siteName,
            'baseUrl' => $app->getBaseUrl(),
            'valuerName' => $valuer->profile->name,
            'registrationNumber' => $registration->number,
            'evaluationLink' => $link,
        ]);

        $app->sendMailMessage($app->createMailMessage([
            'to' => $valuer->profile->emailPrivado ?: $valuer->email,
            'subject' => $message['title'],
            'body' => $message['body'],
        ]), true);

        $notification = new Notification;
        $notification->user = $valuer;
        $notification->message = sprintf(
            i::__('O cadastro %s foi corrigido e reenviado pelo mestre. <a href="%s">Avalie de novo</a>.'),
            $registration->number,
            $link
        );
        $notification->save(true);
    }

    /** @param array<array{name: string, label: string, obs: string}> $refused */
    private function notifyOwner(Registration $registration, array $refused): void
    {
        $app = App::i();

        $app->enqueueJob(SendMailNotification::SLUG, [
            'template' => self::MAIL_RETURNED,
            'registrationId' => $registration->id,
            'params' => ['registrationLink' => $registration->singleUrl, 'campos' => $refused],
        ]);

        $notification = new Notification;
        $notification->user = $registration->owner->user;
        $notification->message = sprintf(
            i::__('Seu cadastro está pendente: um ou mais dados não foram comprovados. <a href="%s">Veja o motivo e reenvie</a>.'),
            $registration->singleUrl
        );
        $notification->save(true);
    }

    /** @return array<string,mixed> */
    private function visibleFields(Registration $registration): array
    {
        $visible = $registration->opportunity->avaliableEvaluationFields;

        return is_array($visible) || is_object($visible) ? (array) $visible : [];
    }

    /** @return array<string,string> */
    private function titles(Registration $registration): array
    {
        $titles = [];

        foreach ($registration->opportunity->registrationFieldConfigurations as $field) {
            $titles[$field->fieldName] = (string) $field->title;
        }

        foreach ($registration->opportunity->registrationFileConfigurations as $file) {
            $titles[$file->fileGroupName] = (string) $file->title;
        }

        return $titles;
    }
}
