<?php

namespace Tests\MDC;

use MDC\Services\PrizeId;
use Tests\Abstract\TestCase;

class PrizeIdTest extends TestCase
{
    function testAsksForTheIdOrTheNotFoundMark()
    {
        $this->assertTrue(PrizeId::isMissing(null, null), 'Sem ID e sem marcar');
        $this->assertTrue(PrizeId::isMissing('  ', ''), 'Espaços não são ID; a caixa desmarcada é gravada como vazio');
        $this->assertFalse(PrizeId::isMissing('12345', null));
        $this->assertFalse(PrizeId::isMissing('', '1'), 'Marcou que não localizou');
        $this->assertFalse(PrizeId::isMissing(null, true));
    }
}
