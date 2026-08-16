<?php

namespace MDC;

/**
 * Plugin da Rede Nacional de Mestras e Mestres das Culturas Tradicionais e Populares.
 *
 * Reúne as funcionalidades do tema `MestresDaCultura`, que fica só com os aspectos visuais.
 *
 * O namespace é `MDC`, e não `MestresDaCultura`, porque o autoloader do projeto mantém um
 * mapa plano de namespace para diretório em que os temas são registrados antes dos plugins:
 * com o nome repetido, o diretório do plugin nunca seria registrado e o plugin seria ignorado
 * sem erro. Pelo mesmo mecanismo, nenhuma classe ou subpasta daqui pode conter `MDC` no nome.
 *
 * @package MDC
 */
class Plugin extends \MapasCulturais\Plugin
{
    /**
     * Registra entidades, metadados e controladores.
     *
     * Exigido por `MapasCulturais\Module`. Ainda sem conteúdo.
     */
    function register()
    {
    }

    /**
     * Registra os hooks do plugin.
     *
     * Exigido por `MapasCulturais\Module`. Ainda sem conteúdo.
     */
    function _init()
    {
    }
}
