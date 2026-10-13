// Globais que os componentes da curadoria da árvore usam; carregado só na página da curadoria.

// Só as partes do d3 que o mapa (mdc-tree-map) usa: layout da árvore, seleção, zoom e transição.
import { hierarchy, tree } from 'd3-hierarchy';
import { select } from 'd3-selection';
import 'd3-transition';
import { zoom, zoomIdentity } from 'd3-zoom';

globalThis.TreeMapD3 = { hierarchy, tree, select, zoom, zoomIdentity };

// Texto, igual em todos os componentes da árvore.
globalThis.MdcTreeText = {
    // Sem acento, caixa e espaços nas pontas, para a busca comparar nomes.
    comparable: text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(),
    // Troca cada `%s` do texto pelo próximo valor.
    format: (template, ...values) => values.reduce((s, v) => s.replace('%s', v), template),
};
