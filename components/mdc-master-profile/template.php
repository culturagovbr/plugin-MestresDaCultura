<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-icon
');
?>
<div v-if="part === 'header'" class="mdc-master-profile mdc-master-profile--header">
    <h1 class="mdc-master-profile__title">{{ profile.knownAs || profile.name }}</h1>
    <p v-if="summary" class="mdc-master-profile__summary">{{ summary }}</p>
    <p class="mdc-master-profile__since">{{ format('na rede desde', profile.since) }}</p>
    <span class="mdc-master-profile__badge"><mc-icon name="circle-checked"></mc-icon> {{ text('selo') }}</span>
</div>

<section v-else-if="part === 'certificate'" class="mdc-master-profile mdc-master-profile--certificate col-12">
    <mc-icon name="mdc-certificate"></mc-icon>
    <h4>{{ text('certificado') }}</h4>
    <p>{{ format('certifica', profile.knownAs || profile.name) }}</p>
    <a class="button button--primary button--icon" :href="profile.certificateUrl">
        <mc-icon name="print"></mc-icon> {{ text('ver certificado') }}
    </a>
</section>

<section v-else-if="part === 'acting' && profile.actingHtml" class="mdc-master-profile mdc-master-profile--acting col-12">
    <h3>{{ text('atuação') }}</h3>
    <div v-html="profile.actingHtml"></div>
</section>
