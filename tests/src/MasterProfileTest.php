<?php

namespace Tests\MDC;

use MDC\Services\MasterProfile;
use Tests\Abstract\TestCase;

class MasterProfileTest extends TestCase
{
    function testHeaderCitesTheMostSpecificActivitiesInAnswerOrder()
    {
        $acting = ['nodes' => [
            ['id' => 1, 'parent' => null, 'name' => 'Saberes e Ofícios'],
            ['id' => 4, 'parent' => 1, 'name' => 'Artesanato'],
            ['id' => 16, 'parent' => 4, 'name' => 'Renda'],
            ['id' => 17, 'parent' => 16, 'name' => 'Bilros'],
            ['id' => 152, 'parent' => null, 'name' => 'Expressões e Manifestações'],
            ['id' => 168, 'parent' => 152, 'name' => 'Coco'],
            ['id' => 200, 'parent' => null, 'name' => 'Culinária'],
        ]];

        $this->assertSame(['Bilros', 'Coco', 'Culinária'], MasterProfile::activities($acting), 'Só o que não tem nada marcado abaixo; um segmento sozinho também conta');
        $this->assertSame([], MasterProfile::activities([]));
    }
}
