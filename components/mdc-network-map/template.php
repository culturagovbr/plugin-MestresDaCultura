<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;
?>
<section v-if="map" class="mdc-network-map">
    <h2 class="mdc-network-map__title"><?php i::_e('Mapa da Rede') ?></h2>
    <p class="mdc-network-map__description"><?php i::_e('Quantas mestras e mestres há em cada estado. Escolha um estado para ver quem são.') ?></p>

    <div :class="['mdc-network-map__body', { 'mdc-network-map__body--all': showAll }]">
        <svg class="mdc-network-map__svg" :viewBox="map.viewBox" role="group" aria-label="<?php i::esc_attr_e('Mapa do Brasil por estado') ?>">
            <g :transform="map.transform">
                <template v-for="(shape, uf) in map.states" :key="uf">
                    <a v-if="count(uf)" :href="url(uf)" :aria-label="label(uf)" class="mdc-network-map__link">
                        <path :d="shape" :class="['mdc-network-map__state', 'mdc-network-map__state--' + level(uf)]" vector-effect="non-scaling-stroke">
                            <title>{{ label(uf) }}</title>
                        </path>
                    </a>
                    <path v-else :d="shape" class="mdc-network-map__state mdc-network-map__state--0" vector-effect="non-scaling-stroke">
                        <title>{{ label(uf) }}</title>
                    </path>
                </template>
            </g>
        </svg>

        <div class="mdc-network-map__side">
            <ul v-if="listed.length" id="mdc-network-map-list" :class="['mdc-network-map__list', { 'mdc-network-map__list--all': showAll }]" :style="{ '--mdc-map-rows': rows }">
                <li v-for="uf in visible" :key="uf">
                    <a :href="url(uf)">{{ names[uf] }} <strong>{{ count(uf) }}</strong></a>
                </li>
            </ul>
            <button v-if="listed.length > top" type="button" class="button button--text mdc-network-map__toggle" aria-controls="mdc-network-map-list" :aria-expanded="showAll ? 'true' : 'false'" @click="showAll = !showAll">
                {{ showAll ? text('ver menos') : text('ver todos') }}
            </button>
            <p v-else class="mdc-network-map__empty"><?php i::_e('Ainda não há mestras e mestres na Rede.') ?></p>

            <div class="mdc-network-map__legend" aria-hidden="true">
                <span class="mdc-network-map__legend-item mdc-network-map__state--0"><?php i::_e('Nenhum') ?></span>
                <span class="mdc-network-map__legend-item mdc-network-map__state--1"><?php i::_e('Menos') ?></span>
                <span class="mdc-network-map__legend-item mdc-network-map__state--4"><?php i::_e('Mais') ?></span>
            </div>
        </div>
    </div>
</section>
