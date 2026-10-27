<?php

namespace MDC\Services;

use MapasCulturais\App;

/**
 * O local do festejo é um Espaço do core, que cada ocorrência da agenda pede. No subsite dos Mestres, os espaços
 * seguem sem busca, painel nem página própria; quem tem conta grava só o próprio local, pelas rotas do formulário.
 */
class FestivityPlace
{
    // O tipo do local; o padrão é "Outros (informar qual)", dos tipos do SNIIC (plugin SNIICDataStandard).
    const CONFIG_KEY = 'mdc.festivityPlaceType';

    const TYPE = 2040;

    // [método, ação] de espaço que ficam abertos.
    const WRITES = [['POST', 'index'], ['PATCH', 'single'], ['PUT', 'single']];

    static function type(): int
    {
        return (int) (App::i()->config[self::CONFIG_KEY] ?? self::TYPE);
    }

    static function isWrite(string $method, string $action): bool
    {
        return in_array([strtoupper($method), $action], self::WRITES, true);
    }
}
