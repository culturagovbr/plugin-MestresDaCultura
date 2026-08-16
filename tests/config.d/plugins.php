<?php

// Habilita o plugin MDC na suíte de testes do repositório principal, sem editar tests/config.d/
// do core — montado via docker-compose.yml deste diretório.
//
// "zz-mdc.d" ordena depois de "config.d" no glob de src/conf/config.php, então este array vence.
// A substituição é total, não uma fusão: array_merge não funde arrays aninhados. Por isso a lista
// base de src/conf/plugins.php é repetida abaixo, com "MDC" acrescentado ao final.
return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'MDC',
    ]
];
