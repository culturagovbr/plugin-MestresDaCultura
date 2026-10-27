<?php
/**
 * Cadastro do festejo em 6 etapas (Controller::GET_festivity, `/cadastrar-festejo/`): o formulário do programa
 * (mdc-festivity-form) sobre o Evento do core.
 *
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->layout = 'default';

$this->import('
    mdc-festivity-form
');

$this->breadcrumb = [
    ['label' => i::__('Início'), 'url' => $app->createUrl('site', 'index')],
    ['label' => i::__('Painel de Controle'), 'url' => $app->createUrl('panel', 'index')],
    ['label' => i::__('Cadastro de Festejo Popular')],
];
?>
<div class="mdc-festivity">
    <mdc-festivity-form></mdc-festivity-form>
</div>
