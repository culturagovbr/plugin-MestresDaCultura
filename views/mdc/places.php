<?php
/**
 * "Meus locais" (Controller::GET_places, `/meus-locais/`): os locais da agenda dos festejos de quem tem a conta, no
 * painel.
 *
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
    mdc-places
');

$this->breadcrumb = [
    ['label' => i::__('Painel de Controle'), 'url' => $app->createUrl('panel', 'index')],
    ['label' => i::__('Meus locais')],
];
?>
<div class="panel-page">
    <header class="panel-page__header">
        <div class="panel-page__header-title">
            <div class="title">
                <div class="title__icon space__background"><mc-icon name="map-pin"></mc-icon></div>
                <h1 class="title__title"><?php i::_e('Meus locais') ?></h1>
            </div>
        </div>
        <p class="panel-page__header-subtitle">
            <?php i::_e('Os locais onde acontecem os seus festejos. Cadastre um local para escolher na agenda do festejo, corrija o nome e o endereço, ou exclua o que nenhum festejo usa.') ?>
        </p>
    </header>

    <mdc-places></mdc-places>
</div>
