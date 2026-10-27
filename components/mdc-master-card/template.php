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
<div class="entity-card mdc-master-card">
    <div class="entity-card__header">
        <div class="mc-avatar">
            <img v-if="master.avatar" :src="master.avatar" alt="">
            <mc-icon v-else name="agent-1"></mc-icon>
        </div>
        <div>
            <div class="entity-card__title">{{ master.knownAs || master.name }}</div>
            <div class="entity-card__subtitle">{{ subtitle }}</div>
        </div>
    </div>
    <div v-if="master.summary" class="entity-card__content-shortDescription">{{ master.summary }}</div>
    <div class="entity-card__footer">
        <a class="button button--secondary-outline button--sm" :href="master.url"><?php i::_e('Ver perfil') ?></a>
    </div>
</div>
