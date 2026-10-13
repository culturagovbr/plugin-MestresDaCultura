<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-icon
');
?>
<div class="mdc-tree-map">
    <svg ref="svg" class="mdc-tree-map__canvas" role="tree" :aria-label="text('mapa')">
        <g :transform="transform">
            <path v-for="link in layout.links" :key="'link-' + link.id" class="mdc-tree-map__link" :class="{ 'mdc-tree-map__link--off': link.off }" :d="link.d"></path>

            <g v-for="item in layout.placed" :key="item.id" class="mdc-tree-map__node" :class="nodeClasses(item)" :transform="`translate(${item.x},${item.y - item.h / 2})`"
                tabindex="0" role="treeitem" :aria-selected="item.id === selected" :aria-label="nodeLabel(item)"
                @click="choose(item)" @keydown.enter.prevent="choose(item)" @keydown.space.prevent="choose(item)">
                <rect class="mdc-tree-map__ring" x="-4" y="-4" :width="item.w + 8" :height="item.h + 8" :rx="item.h / 2 + 4"></rect>
                <rect class="mdc-tree-map__box" :width="item.w" :height="item.h" :rx="item.h / 2"></rect>
                <circle v-if="showDuplicates && item.duplicate" class="mdc-tree-map__flag" :cx="item.side === 'left' ? 4 : item.w - 4" cy="2" r="5.5"></circle>
                <text class="mdc-tree-map__label" :x="item.w / 2" :y="item.h / 2" dy="0.35em" text-anchor="middle">
                    <tspan class="mdc-tree-map__name">{{item.name}}</tspan><tspan v-if="item.id" class="mdc-tree-map__count" dx="7">{{item.count}}</tspan>
                </text>

                <g v-if="item.kids" class="mdc-tree-map__toggle" role="button" :aria-label="expanded.has(item.id) ? text('fechar ramo') : format('abrir ramo', item.kids)" @click.stop="toggle(item.id)">
                    <circle :cx="item.side === 'left' ? -12 : item.w + 12" :cy="item.h / 2" r="10"></circle>
                    <text :x="item.side === 'left' ? -12 : item.w + 12" :y="item.h / 2" dy="0.35em" text-anchor="middle">{{expanded.has(item.id) ? '−' : '+' + item.kids}}</text>
                </g>
            </g>
        </g>
    </svg>

    <div class="mdc-tree-map__zoom">
        <button type="button" class="button button--icon button--sm" :aria-label="text('aproximar')" @click="zoomBy(1.25)"><mc-icon name="mdc-zoom-in"></mc-icon></button>
        <button type="button" class="button button--icon button--sm" :aria-label="text('afastar')" @click="zoomBy(0.8)"><mc-icon name="mdc-zoom-out"></mc-icon></button>
    </div>

    <p class="mdc-tree-map__legend" aria-hidden="true">
        <span><i class="mdc-tree-map__swatch"></i>{{text('ativo')}}</span>
        <span><i class="mdc-tree-map__swatch mdc-tree-map__swatch--off"></i>{{text('desativado')}}</span>
        <span v-if="showDuplicates"><i class="mdc-tree-map__dot"></i>{{text('duplicata')}}</span>
        <span>{{text('legenda ramo')}}</span>
        <span>{{text('legenda uso')}}</span>
    </p>
</div>
