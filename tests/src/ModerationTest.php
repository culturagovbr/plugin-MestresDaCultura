<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Registration;
use MDC\Services\Moderation;
use Tests\Abstract\TestCase;

class ModerationTest extends TestCase
{
    function testReopensOnlyTheRefusedFieldsTheValuerCouldSee()
    {
        $data = [
            '41' => ['label' => 'Anexar documento comprobatório', 'evaluation' => 'invalid', 'obs' => ' Não comprova. '],
            '355' => ['label' => 'Reconhecimento formal?', 'evaluation' => 'valid', 'obs' => ''],
            '356' => ['label' => 'Há quantos anos?', 'evaluation' => '', 'obs' => ''],
            '90' => ['label' => 'Campo que o avaliador não via', 'evaluation' => 'invalid', 'obs' => ''],
        ];
        $visible = ['rfc_41' => 'true', 'field_355' => 'true', 'field_356' => 'true'];
        $titles = ['rfc_41' => 'Anexar documento comprobatório', 'field_41' => 'Nome completo', 'field_355' => 'Reconhecimento formal?', 'field_356' => 'Há quantos anos?', 'field_90' => 'Campo que o avaliador não via'];

        $this->assertSame(
            [['name' => 'rfc_41', 'label' => 'Anexar documento comprobatório', 'obs' => 'Não comprova.']],
            Moderation::refusedFields($data, $visible, $titles),
            'O número 41 é de um campo e de um anexo: vale o anexo, que o avaliador via; válido, sem avaliação e invisível não reabrem'
        );
    }

    // O motivo leva a justificativa e o descumprimento que o moderador escreveu, o que tiver.
    function testTheReasonHasTheJustificationAndTheNonCompliance()
    {
        $data = [
            '7' => ['label' => 'Declaração', 'evaluation' => 'invalid', 'obs' => '', 'obsItems' => ' Sem assinatura da liderança. '],
            '8' => ['label' => 'Foto', 'evaluation' => 'invalid', 'obs' => 'Foto escura.', 'obsItems' => 'Não mostra o trabalho.'],
        ];
        $visible = ['rfc_7' => 'true', 'rfc_8' => 'true'];
        $titles = ['rfc_7' => 'Declaração', 'rfc_8' => 'Foto'];

        $this->assertSame(
            ['Sem assinatura da liderança.', 'Foto escura. Não mostra o trabalho.'],
            array_column(Moderation::refusedFields($data, $visible, $titles), 'obs')
        );
    }

    function testTellsFieldAndAttachmentWithTheSameNumberApartByTitle()
    {
        $data = ['7' => ['label' => 'Declaração da comunidade', 'evaluation' => 'invalid', 'obs' => 'Sem assinatura.']];
        $visible = ['field_7' => 'true', 'rfc_7' => true];
        $titles = ['field_7' => 'Descrição do reconhecimento', 'rfc_7' => 'Declaração da comunidade'];

        $this->assertSame(['rfc_7'], array_column(Moderation::refusedFields($data, $visible, $titles), 'name'));
    }

    // Em análise: o primeiro envio ou a correção reenviada; devolvido, rascunho e aprovado não.
    function testUnderReviewIsSentAndWaitingForTheModeration()
    {
        $this->assertTrue(Moderation::underReview(Registration::STATUS_SENT, null, null), 'Enviado, sem campos reabertos');
        $this->assertTrue(Moderation::underReview(Registration::STATUS_SENT, ['rfc_41'], '2026-10-26 10:00:00'), 'Correção reenviada');

        $this->assertFalse(Moderation::underReview(Registration::STATUS_SENT, ['rfc_41'], null), 'Devolvido, à espera da correção');
        $this->assertFalse(Moderation::underReview(Registration::STATUS_DRAFT, null, null));
        $this->assertFalse(Moderation::underReview(Registration::STATUS_APPROVED, null, null));
    }
}
