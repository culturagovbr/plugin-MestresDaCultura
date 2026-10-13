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
        public readonly array $children = [],
    ) {
    }
}
