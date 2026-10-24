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
 * quem avaliou, para uma nova análise. Na aprovação, o mestre é avisado.
 */
class Moderation
{
    // Data-limite dos campos reabertos, tão distante que não chega.
    const NO_DEADLINE = '2111-01-01 00:00:00';

    const MAIL_RETURNED = 'mdc_cadastro_pendente';

    const MAIL_RESENT = 'mdc_cadastro_reenviado';

    const MAIL_APPROVED = 'mdc_cadastro_aprovado';

    /**
     * Os campos que o avaliador marcou, ok (`valid`) ou não ok (`invalid`), pelo nome que a inscrição usa (`field_N`
     * ou `rfc_N`), com o motivo. Número de campo e de anexo repetido vale pelo que o avaliador via e, nos dois, pelo título.
     *
     * @param array $evaluationData número => {label, evaluation, obs}
     * @param array<string,mixed> $visible nome => "true", os campos que o avaliador vê
     * @param array<string,string> $titles nome => título
     * @return array<array{name: string, label: string, result: string, obs: string}>
     */
    static function evaluatedFields(array $evaluationData, array $visible, array $titles): array
    {
        $evaluated = [];

        foreach ($evaluationData as $id => $item) {
            $item = (array) $item;
            $result = $item['evaluation'] ?? '';

            if (!in_array($result, ['valid', 'invalid'], true)) {
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
                $evaluated[] = [
                    'name' => $candidates[0],
                    'label' => (string) ($item['label'] ?? $titles[$candidates[0]]),
                    'result' => $result,
                    'obs' => trim((string) ($item['obs'] ?? '')),
                ];
            }
        }

        return $evaluated;
    }

    /**
     * Os campos não ok, que voltam ao mestre.
     *
     * @return array<array{name: string, label: string, obs: string}>
     */
    static function refusedFields(array $evaluationData, array $visible, array $titles): array
    {
        $refused = array_filter(self::evaluatedFields($evaluationData, $visible, $titles), fn ($field) => $field['result'] === 'invalid');

        return array_values(array_map(fn ($field) => array_diff_key($field, ['result' => true]), $refused));
    }

    /** Situações da moderação, na ordem do caminho do cadastro. */
    const SITUATIONS = ['draft', 'waiting', 'returned', 'resent', 'approved'];

    /** @return array<string, string> o nome de cada situação, para a gestão */
    static function situationLabels(): array
    {
        return [
            'draft' => i::__('Rascunho'),
            'waiting' => i::__('Aguardando moderação'),
            'returned' => i::__('Devolvido ao mestre'),
            'resent' => i::__('Reenviado'),
            'approved' => i::__('Aprovado'),
        ];
    }

    /**
     * Em que ponto da moderação o cadastro está, pelos valores da linha. Enviado: devolvido com campos reabertos e a
     * correção não enviada, reenviado depois disso, aguardando sem campos reabertos. Fora dos status do programa, null.
     */
    static function situation(int $status, mixed $editableFields, mixed $editSentTimestamp): ?string
    {
        return match ($status) {
            Registration::STATUS_DRAFT => 'draft',
            Registration::STATUS_APPROVED => 'approved',
            Registration::STATUS_SENT => !$editableFields ? 'waiting' : ($editSentTimestamp ? 'resent' : 'returned'),
            default => null,
        };
    }

    /** Devolvido e ainda não reenviado: há campos reabertos e a correção não foi enviada. */
    static function isPending(Registration $registration): bool
    {
        return self::situation($registration->status, $registration->editableFields, $registration->editSentTimestamp) === 'returned';
    }

    /**
     * O que o mestre vê enquanto o cadastro está pendente: o resultado de cada campo moderado, com o motivo, e o
     * caminho para corrigir, já na etapa do primeiro campo recusado. O nome de quem avaliou não vai.
     *
     * @return ?array{status: 'pending', fields: array<array{name: string, label: string, result: string, obs: string, section: string}>, editUrl: string}
     */
    function pendingView(Registration $registration): ?array
    {
        if (!self::isPending($registration)) {
            return null;
        }

        $app = App::i();
        $visible = $this->visibleFields($registration);
        $titles = $this->titles($registration);
        $fields = [];

        foreach ($app->repo('RegistrationEvaluation')->findBy(['registration' => $registration, 'status' => RegistrationEvaluation::STATUS_SENT]) as $evaluation) {
            array_push($fields, ...self::evaluatedFields((array) $evaluation->evaluationData, $visible, $titles));
        }

        if (!$fields) {
            return null;
        }

        $sections = $this->sections($registration);
        $fields = array_map(fn ($field) => $field + ['section' => $sections[$field['name']] ?? ''], $fields);

        $refused = array_values(array_filter($fields, fn ($field) => $field['result'] === 'invalid'));
        $url = $app->createUrl('registration', 'registrationEdit', [$registration->id]);
        $step = $refused ? $this->stepNumber($registration, $refused[0]['name']) : null;

        return ['status' => 'pending', 'fields' => $fields, 'editUrl' => $step ? "{$url}#etapa_{$step}" : $url];
    }

    /**
     * O título da seção de cada campo e anexo ("Reconhecimento pela comunidade"), na ordem de cada etapa.
     *
     * @return array<string,string> nome => título da seção
     */
    private function sections(Registration $registration): array
    {
        $items = [];

        foreach ($registration->opportunity->registrationFieldConfigurations as $field) {
            $items[] = [$field->step?->id, $field->displayOrder, $field->fieldName, $field->fieldType === 'section' ? (string) $field->title : null];
        }

        foreach ($registration->opportunity->registrationFileConfigurations as $file) {
            $items[] = [$file->step?->id, $file->displayOrder, $file->fileGroupName, null];
        }

        usort($items, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $sections = [];
        $current = [];

        foreach ($items as [$step, , $name, $section]) {
            if ($section !== null) {
                $current[$step] = $section;
            } else {
                $sections[$name] = $current[$step] ?? '';
            }
        }

        return $sections;
    }

    // A edição do core abre na etapa de `#etapa_N`, contada na ordem das etapas a partir de 1.
    private function stepNumber(Registration $registration, string $name): ?int
    {
        $opportunity = $registration->opportunity;
        $step = null;

        foreach ($opportunity->registrationFieldConfigurations as $field) {
            $step = $field->fieldName === $name ? $field->step : $step;
        }

        foreach ($opportunity->registrationFileConfigurations as $file) {
            $step = $file->fileGroupName === $name ? $file->step : $step;
        }

        $steps = $opportunity->registrationSteps;
        $steps = is_array($steps) ? $steps : ($steps?->toArray() ?? []);
        usort($steps, fn ($a, $b) => $a->displayOrder <=> $b->displayOrder);

        foreach (array_values($steps) as $index => $candidate) {
            if ($step && $candidate->id === $step->id) {
                return $index + 1;
            }
        }

        return null;
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
     * O mestre reenviou a correção: as avaliações enviadas desta inscrição voltam a rascunho, com as marcações, e quem
     * avaliou é avisado; a de moderador desabilitado passa a outro.
     */
    function sendBackToValuers(Registration $registration): void
    {
        $app = App::i();
        $evaluations = $app->repo('RegistrationEvaluation')->findBy(['registration' => $registration, 'status' => RegistrationEvaluation::STATUS_SENT]);
        $queue = new ModerationQueue;

        // Altera a avaliação sem checar a permissão do mestre que reenviou.
        $app->disableAccessControl();

        try {
            foreach ($evaluations as $evaluation) {
                // A correção do moderador desabilitado vai para outro.
                if (!$queue->isActive($evaluation)) {
                    $queue->releaseEvaluation($evaluation);
                    continue;
                }

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

    /** Avisa o mestre da aprovação, por e-mail com o certificado e no sino. */
    function notifyApproved(Registration $registration, ?string $certificateUrl = null): void
    {
        $app = App::i();

        $app->enqueueJob(SendMailNotification::SLUG, [
            'template' => self::MAIL_APPROVED,
            'registrationId' => $registration->id,
            'params' => ['registrationLink' => $registration->singleUrl, 'certificateLink' => $certificateUrl],
        ]);

        $notification = new Notification;
        $notification->user = $registration->owner->user;
        $notification->message = sprintf(
            i::__('Seu cadastro na Rede de Mestras e Mestres foi aprovado. <a href="%s">Veja o seu cadastro</a>.'),
            $registration->singleUrl
        );

        $app->disableAccessControl();

        try {
            $notification->save(true);
        } finally {
            $app->enableAccessControl();
        }
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
