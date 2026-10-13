<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
');
?>

<div class="panel-page">
    <header class="panel-page__header">
        <div class="panel-page__header-title">
            <div class="title">
                <div class="title__icon primary__background">
                    <mc-icon name="mdc-tree"></mc-icon>
                </div>
                <h1 class="title__title"><?php i::_e('Curadoria da Árvore de Atuação') ?></h1>
            </div>
        </div>
        <p class="panel-page__header-subtitle">
            <?php i::_e('Segmento › Tipologia › Subtipologia › Atividade: a lista que aparece na pergunta "Atuação Cultural" do cadastro.') ?>
        </p>
    </header>
</div>
