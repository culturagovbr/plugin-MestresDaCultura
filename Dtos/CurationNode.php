<?php

namespace MDC\Dtos;

/** Item da árvore como a curadoria vê: inclui os desativados e quantos cadastros o marcaram. */
class CurationNode
{
    /** @param CurationNode[] $children */
    function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $enabled,
        // Cadastros enviados que marcaram o item; os pais também contam.
        public readonly int $sent,
        // Rascunhos que marcaram o item: são os avisados quando ele sai da lista.
        public readonly int $drafts,
        // Referência de que o item é equivalente (ver TreeEquivalence), ou null.
        public readonly ?int $equivalentTo = null,
        // Possíveis duplicatas: outros itens com o mesmo nome, ou quase (ver TreeDuplicates).
        public readonly array $duplicates = [],
        // O nome se repete em outros lugares de propósito (repetição legítima marcada).
        public readonly bool $legitimate = false,
        public readonly array $children = [],
    ) {
    }
}
