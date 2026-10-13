<?php

namespace MDC\Dtos;

/** Um texto da fila "Não encontrou?": o que as pessoas escreveram no "Outro", agrupado pelo lugar. */
class OtherEntry
{
    /**
     * @param int[] $suggestions ids de itens ativos com nome igual ou parecido
     * @param array{type: string, node: ?int, note: ?string, at: string}|null $decision
     */
    function __construct(
        // Item onde o "Outro" foi escrito; null é o "Não encontrou sua atuação na lista?" geral.
        public readonly ?int $parent,
        // Texto normalizado (TreeText::comparable): a chave do grupo.
        public readonly string $key,
        // A grafia mais usada no grupo.
        public readonly string $text,
        public readonly int $sent,
        public readonly int $drafts,
        public readonly array $suggestions = [],
        public readonly ?array $decision = null,
    ) {
    }
}
