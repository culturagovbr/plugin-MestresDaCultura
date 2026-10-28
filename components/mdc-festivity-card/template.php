<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-icon
');
?>
<a class="mdc-festivity-card" :href="festivity.url">
    <img v-if="festivity.avatar" :src="festivity.avatar" alt="" class="mdc-festivity-card__image">
    <span v-else class="mdc-festivity-card__image mdc-festivity-card__image--empty"><mc-icon name="celebration"></mc-icon></span>
    <span class="mdc-festivity-card__body">
        <span v-if="festivity.agenda" class="mdc-festivity-card__date">{{ festivity.agenda }}</span>
        <strong class="mdc-festivity-card__name">{{ festivity.name }}</strong>
        <span v-if="festivity.classification" class="mdc-festivity-card__info">{{ festivity.classification }}</span>
        <span v-if="festivity.details" class="mdc-festivity-card__info">{{ festivity.details }}</span>
    </span>
</a>
