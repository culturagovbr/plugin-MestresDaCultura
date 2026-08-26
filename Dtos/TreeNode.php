<?php

namespace MDC\Dtos;

class TreeNode
{
    /** @param TreeNode[] $children */
    function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $children = [],
    ) {
    }
}
