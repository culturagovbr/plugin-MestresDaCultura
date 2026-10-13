<?php

namespace MDC\Services;

use MapasCulturais\App;
use MDC\Dtos\TreeNode;
use MDC\Entities\Node;

/**
 * A árvore ativa, sem os desativados, no formato de data/atuacao.json, a fonte da carga inicial.
 */
class TreeExport
{
    static function json(): string
    {
        $json = json_encode(self::entries(App::i()->repo(Node::class)->findTree()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // O arquivo usa 2 espaços por nível; o json_encode, 4.
        return preg_replace_callback('/^ +/m', fn ($indent) => str_repeat(' ', strlen($indent[0]) / 2), $json) . "\n";
    }

    /**
     * @param TreeNode[] $nodes
     * @return array<array{name: string, children?: array}>
     */
    private static function entries(array $nodes): array
    {
        return array_map(
            fn (TreeNode $node) => $node->children ? ['name' => $node->name, 'children' => self::entries($node->children)] : ['name' => $node->name],
            $nodes
        );
    }
}
