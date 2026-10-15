<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-icon
');
?>
<div class="mdc-certificate-actions">
    <button type="button" class="button button--text button--icon" @click="back()">
        <mc-icon name="arrow-left"></mc-icon> {{ text('voltar') }}
    </button>
    <button type="button" class="button button--primary button--icon" @click="print()">
        <mc-icon name="print"></mc-icon> {{ text('imprimir') }}
    </button>
</div>
