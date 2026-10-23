<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
?>
<small v-if="age !== null" class="field__description mdc-age">{{ (age === 1 ? text('um ano') : text('anos')).replace('%s', age) }}</small>
