<?php

namespace Tests\MDC;

use MDC\Entities\Node;
use MDC\Services\TreeHistory;
use Tests\Abstract\TestCase;

class TreeHistoryTest extends TestCase
{
    private function revision(string $at, ?string $who, array $data): array
    {
        return ['action' => 'modified', 'at' => $at, 'who' => $who, 'data' => $data + ['level' => 3]];
    }

    function testTellsWhatChangedInEachRevisionNewestFirst()
    {
        $luthieria = ['id' => 60, 'name' => 'Luthieria'];
        $musica = ['id' => 272, 'name' => 'Música e Instrumentos Populares'];
        $base = ['name' => 'Bandolim', 'parent' => $luthieria, 'displayOrder' => 5, 'status' => Node::STATUS_ENABLED];

        $history = TreeHistory::describe([
            $this->revision('2026-10-09 15:57', null, $base),
            $this->revision('2026-10-13 18:00', 'Ana', ['name' => 'Bandolins'] + $base),
            $this->revision('2026-10-13 18:01', 'Ana', ['name' => 'Bandolins'] + $base),
            $this->revision('2026-10-13 18:02', 'Ana', ['name' => 'Bandolins', 'parent' => $musica, 'displayOrder' => 19] + $base),
            $this->revision('2026-10-13 18:03', 'Bia', ['name' => 'Bandolins', 'parent' => $musica, 'displayOrder' => 18, 'status' => Node::STATUS_DISABLED] + $base),
        ]);

        $this->assertSame([
            ['at' => '2026-10-13 18:03', 'who' => 'Bia', 'changes' => ['mudou a ordem', 'desativou']],
            ['at' => '2026-10-13 18:02', 'who' => 'Ana', 'changes' => ['moveu de "Luthieria" para "Música e Instrumentos Populares"']],
            ['at' => '2026-10-13 18:00', 'who' => 'Ana', 'changes' => ['renomeou de "Bandolim" para "Bandolins"']],
            ['at' => '2026-10-09 15:57', 'who' => 'carga inicial', 'changes' => ['criou o item']],
        ], $history, 'Revisão sem mudança visível fica de fora; mover não conta também como mudar a ordem');
    }
}
