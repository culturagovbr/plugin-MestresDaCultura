<?php

namespace MDC\Services;

/** Comparação de textos da árvore sem caixa, acento e espaços repetidos: "Coco de Roda" e "côco  de roda" são o mesmo. */
class TreeText
{
    // Os acentos do português, à mão, sem a extensão intl.
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    static function comparable(string $text): string
    {
        return strtr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text))), self::ACCENTS);
    }
}
