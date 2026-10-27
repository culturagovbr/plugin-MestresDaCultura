<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-icon
');
?>
<div v-if="part === 'header'" class="mdc-festivity-profile mdc-festivity-profile--header">
    <p v-if="classification" class="mdc-festivity-profile__classification">{{ classification }}</p>
    <ul class="mdc-festivity-profile__chips">
        <li v-if="profile.certificateUrl" class="mdc-festivity-profile__badge"><mc-icon name="circle-checked"></mc-icon> {{ text('no calendario') }}</li>
        <li v-if="profile.place" class="mdc-festivity-profile__chip"><mc-icon name="pin"></mc-icon> {{ profile.place }}</li>
        <li v-if="profile.size" class="mdc-festivity-profile__chip">{{ format('porte', profile.size) }}</li>
        <li v-if="history" class="mdc-festivity-profile__chip">{{ history }}</li>
    </ul>
</div>

<div v-else-if="part === 'main'" class="mdc-festivity-profile mdc-festivity-profile--main col-12">
    <section v-if="profile.usualPeriod || profile.venue || profile.territory" class="mdc-festivity-profile__section">
        <h3>{{ text('quando e onde') }}</h3>
        <dl class="mdc-festivity-profile__facts">
            <template v-if="profile.usualPeriod"><dt>{{ text('costuma acontecer') }}</dt><dd>{{ profile.usualPeriod }}</dd></template>
            <template v-if="profile.venue"><dt>{{ text('onde') }}</dt><dd>{{ profile.venue }}</dd></template>
            <template v-if="profile.territory"><dt>{{ text('territorio') }}</dt><dd>{{ profile.territory }}</dd></template>
        </dl>
    </section>

    <section v-if="profile.masters.length" class="mdc-festivity-profile__section">
        <h3>{{ text('mestres') }}</h3>
        <ul class="mdc-festivity-profile__masters">
            <li v-for="master in profile.masters" :key="master.id">
                <a :href="master.url" class="mdc-festivity-profile__master">
                    <img v-if="master.avatar" :src="master.avatar" alt="" class="mdc-festivity-profile__avatar">
                    <span v-else class="mdc-festivity-profile__avatar mdc-festivity-profile__avatar--empty"><mc-icon name="agent"></mc-icon></span>
                    <span><strong>{{ master.name }}</strong><small v-if="master.city">{{ master.city }}</small></span>
                </a>
            </li>
        </ul>
    </section>

    <section v-if="profile.accessibility.length || profile.shelter.length" class="mdc-festivity-profile__section">
        <h3>{{ text('acessibilidade e acolhimento') }}</h3>
        <ul v-if="profile.accessibility.length" class="mdc-festivity-profile__tags" :aria-label="text('acessibilidade')">
            <li v-for="item in profile.accessibility" :key="item">{{ item }}</li>
        </ul>
        <ul v-if="profile.shelter.length" class="mdc-festivity-profile__tags" :aria-label="text('acolhimento')">
            <li v-for="item in profile.shelter" :key="item">{{ item }}</li>
        </ul>
    </section>

    <section v-if="profile.economy.length || profile.economyOther" class="mdc-festivity-profile__section">
        <h3>{{ text('economia') }}</h3>
        <ul class="mdc-festivity-profile__economy">
            <li v-for="group in profile.economy" :key="group.segment"><strong>{{ group.segment }}:</strong> {{ group.items.join(', ') }}</li>
            <li v-if="profile.economyOther">{{ profile.economyOther }}</li>
        </ul>
    </section>

    <section v-if="profile.culturePoint" class="mdc-festivity-profile__section">
        <h3>{{ text('ponto') }}</h3>
        <p>{{ profile.culturePoint }}</p>
    </section>
</div>

<div v-else-if="part === 'aside'" class="mdc-festivity-profile mdc-festivity-profile--aside col-12">
    <section v-if="profile.certificateUrl" class="mdc-festivity-profile__certificate">
        <mc-icon name="mdc-certificate"></mc-icon>
        <h4>{{ text('certificado') }}</h4>
        <p>{{ format('certifica desde', profile.inCalendarSince) }}</p>
        <a class="button button--primary button--icon" :href="profile.certificateUrl">
            <mc-icon name="print"></mc-icon> {{ text('ver certificado') }}
        </a>
    </section>

    <section class="mdc-festivity-profile__producer">
        <h4>{{ text('quem realiza') }}</h4>
        <dl class="mdc-festivity-profile__facts">
            <template v-if="profile.producer"><dt>{{ profile.producer }}</dt><dd>{{ profile.institution || '—' }}<span v-if="profile.institutionIsPoint" class="mdc-festivity-profile__point">{{ text('e ponto') }}</span></dd></template>
            <template v-if="profile.email || profile.phone">
                <dt>{{ text('contato') }}</dt>
                <dd>
                    <a v-if="profile.email" :href="'mailto:' + profile.email">{{ profile.email }}</a>
                    <a v-if="profile.phone" :href="'tel:' + profile.phone.replace(/[^0-9+]/g, '')">{{ profile.phone }}</a>
                </dd>
            </template>
            <template v-if="profile.law"><dt>{{ text('reconhecimento') }}</dt><dd>{{ text('lei propria') }} <a v-if="profile.lawUrl" :href="profile.lawUrl" target="_blank" rel="noopener">{{ text('ver lei') }}</a></dd></template>
            <template v-if="profile.funding.length">
                <dt>{{ text('recursos') }}</dt>
                <dd><span v-for="source in profile.funding" :key="source.label" class="mdc-festivity-profile__funding">{{ source.years ? format('fonte anos', source.label, source.years) : source.label }}</span></dd>
            </template>
        </dl>
    </section>
</div>
