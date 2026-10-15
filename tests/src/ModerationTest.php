<?php

namespace Tests\MDC;

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

    function testTellsFieldAndAttachmentWithTheSameNumberApartByTitle()
    {
        $data = ['7' => ['label' => 'Declaração da comunidade', 'evaluation' => 'invalid', 'obs' => 'Sem assinatura.']];
        $visible = ['field_7' => 'true', 'rfc_7' => true];
        $titles = ['field_7' => 'Descrição do reconhecimento', 'rfc_7' => 'Declaração da comunidade'];

        $this->assertSame(['rfc_7'], array_column(Moderation::refusedFields($data, $visible, $titles), 'name'));
    }
}
