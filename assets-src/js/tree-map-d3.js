// Bibliotecas do mapa da curadoria (componente mdc-tree-map), no padrão do core (modules/Components,
// vue-init.js): os componentes são carregados sem build, então o que eles usam vai como global. Só as
// partes do d3 que o mapa usa: layout da árvore, seleção, zoom e transição.
import { hierarchy, tree } from 'd3-hierarchy';
import { select } from 'd3-selection';
import 'd3-transition';
import { zoom, zoomIdentity } from 'd3-zoom';

globalThis.TreeMapD3 = { hierarchy, tree, select, zoom, zoomIdentity };
