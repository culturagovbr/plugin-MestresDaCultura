<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

// A busca só traz festejos do Calendário, com o selo dos festejos.
$this->jsObject['config']['mdcFestivityField'] = ['sealId' => (new \MDC\Services\FestivitySeal)->configuredId()];
