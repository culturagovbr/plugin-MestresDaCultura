// Medidas do desenho, por nível (0 = raiz, 1 = segmento…). Fonte e tamanho são os do _mdc-tree-map.scss
// do tema: a largura de cada caixa é medida com eles.
const MDC_TREE_MAP = {
    row: 34,
    gap: 56,
    height: [40, 32, 26, 26, 26],
    font: ['700 15px', '700 13px', '400 12px', '400 12px', '400 12px'],
    padding: [30, 30, 22, 22, 22],
    countFont: '600 10px',
    countGap: 7,
    fontFamily: '"Open Sans", sans-serif',
};

app.component('mdc-tree-map', {
    template: $TEMPLATES['mdc-tree-map'],
    emits: ['select'],

    props: {
        // Segmentos da árvore da curadoria: {id, name, enabled, sent, drafts, children}
        tree: {
            type: Array,
            required: true,
        },
        selected: {
            type: Number,
            default: null,
        },
        showDisabled: {
            type: Boolean,
            default: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-tree-map');

        // substitui cada `%s` do texto pelo próximo argumento
        const format = (key, ...values) => values.reduce((s, v) => s.replace('%s', v), text(key));

        return { text, format };
    },

    data() {
        return {
            expanded: new Set([0]),
            transform: '',
        };
    },

    computed: {
        root() {
            return { id: 0, name: this.text('raiz'), enabled: true, sent: 0, children: this.tree };
        },

        // id => { node, parent, depth, segment }: o segmento dá a cor do ramo.
        index() {
            const index = {};
            const visit = (node, parent, depth, segment) => {
                index[node.id] = { node, parent, depth, segment };
                node.children.forEach((child, position) => visit(child, node.id, depth + 1, depth === 0 ? position + 1 : segment));
            };

            visit(this.root, null, 0, 0);

            return index;
        },

        // Na ordem do formulário: a primeira metade dos segmentos à direita, o resto à esquerda.
        layout() {
            const { hierarchy, tree } = TreeMapD3;
            const rootWidth = this.boxWidth(this.root, 0);
            const placed = [{ ...this.describe(this.root, 0, 'center'), x: -rootWidth / 2, y: 0, w: rootWidth }];
            const links = [];
            const segments = this.visibleChildren(this.root);
            const half = Math.ceil(segments.length / 2);

            for (const [side, list] of [['right', segments.slice(0, half)], ['left', segments.slice(half)]]) {
                if (!list.length) {
                    continue;
                }

                const build = node => ({ node, children: this.expanded.has(node.id) ? this.visibleChildren(node).map(build) : [] });
                const branch = hierarchy({ node: this.root, children: list.map(build) });
                tree().nodeSize([MDC_TREE_MAP.row, 1]).separation((a, b) => (a.parent === b.parent ? 1 : 1.35))(branch);

                // Uma coluna por nível, na largura da maior caixa visível dele.
                const widest = [];
                branch.each(d => d.depth && (widest[d.depth] = Math.max(widest[d.depth] || 0, this.boxWidth(d.data.node, d.depth))));
                const column = [0, rootWidth / 2 + MDC_TREE_MAP.gap];
                for (let depth = 2; depth < widest.length; depth++) {
                    column[depth] = column[depth - 1] + widest[depth - 1] + MDC_TREE_MAP.gap;
                }

                const ys = branch.descendants().filter(d => d.depth).map(d => d.x);
                const middle = (Math.min(...ys) + Math.max(...ys)) / 2;
                const byId = {};

                branch.each(d => {
                    if (!d.depth) {
                        return;
                    }

                    const w = this.boxWidth(d.data.node, d.depth);
                    const item = { ...this.describe(d.data.node, d.depth, side), x: side === 'right' ? column[d.depth] : -column[d.depth] - w, y: d.x - middle, w };
                    byId[item.id] = item;
                    placed.push(item);
                });

                branch.each(d => {
                    if (!d.depth) {
                        return;
                    }

                    const parent = d.parent.depth ? byId[d.parent.data.node.id] : placed[0];
                    const child = byId[d.data.node.id];
                    const from = side === 'right' ? parent.x + parent.w : parent.x;
                    const to = side === 'right' ? child.x : child.x + child.w;
                    const bend = (from + to) / 2;

                    links.push({ id: child.id, off: !child.enabled, d: `M${from},${parent.y} C${bend},${parent.y} ${bend},${child.y} ${to},${child.y}` });
                });
            }

            return { placed, links };
        },
    },

    watch: {
        selected(id) {
            if (id !== null) {
                this.reveal(id);
            }
        },
    },

    mounted() {
        const { select, zoom } = TreeMapD3;

        this.zoom = zoom().scaleExtent([0.2, 2.2]).on('zoom', event => (this.transform = event.transform.toString()));
        this.svg = select(this.$refs.svg).call(this.zoom).on('dblclick.zoom', null);
        this.$nextTick(() => this.fit());
    },

    methods: {
        visibleChildren(node) {
            return node.children.filter(child => this.showDisabled || child.enabled);
        },

        describe(node, depth, side) {
            const kids = depth ? this.visibleChildren(node).length : 0;

            return {
                id: node.id,
                name: node.name,
                enabled: node.enabled,
                count: node.sent,
                depth,
                side,
                h: MDC_TREE_MAP.height[depth],
                kids,
                segment: this.index[node.id]?.segment || 0,
            };
        },

        boxWidth(node, depth) {
            const count = depth ? MDC_TREE_MAP.countGap + this.measure(String(node.sent), MDC_TREE_MAP.countFont) : 0;

            return Math.ceil(this.measure(node.name, MDC_TREE_MAP.font[depth]) + count) + MDC_TREE_MAP.padding[depth];
        },

        measure(text, font) {
            this.widths ??= new Map();
            const key = `${font}|${text}`;

            if (!this.widths.has(key)) {
                this.context ??= document.createElement('canvas').getContext('2d');
                this.context.font = `${font} ${MDC_TREE_MAP.fontFamily}`;
                this.widths.set(key, this.context.measureText(text).width);
            }

            return this.widths.get(key);
        },

        nodeClasses(item) {
            return [
                `mdc-tree-map__node--depth-${item.depth}`,
                `mdc-tree-map__node--segment-${item.segment}`,
                {
                    'mdc-tree-map__node--off': !item.enabled,
                    'mdc-tree-map__node--selected': item.id === this.selected,
                },
            ];
        },

        nodeLabel(item) {
            return this.format(item.enabled ? 'item' : 'item desativado', item.name, item.count);
        },

        // Clicar escolhe o item e abre o ramo dele; fechar fica no círculo.
        choose(item) {
            if (item.id && item.kids) {
                this.expanded.add(item.id);
            }

            this.$emit('select', item.id);
        },

        toggle(id) {
            this.expanded.has(id) ? this.expanded.delete(id) : this.expanded.add(id);
        },

        // Abre o caminho até o item e o centraliza.
        reveal(id) {
            for (let parent = this.index[id]?.parent; parent !== null && parent !== undefined; parent = this.index[parent].parent) {
                this.expanded.add(parent);
            }

            this.$nextTick(() => this.centerOn(id));
        },

        openBranch(id) {
            const open = node => {
                this.expanded.add(node.id);
                node.children.forEach(open);
            };

            open(this.index[id].node);
            this.$nextTick(() => this.fit(this.branchIds(id)));
        },

        closeAll() {
            this.expanded = new Set([0]);
            this.$nextTick(() => this.fit());
        },

        branchIds(id) {
            const ids = new Set();
            const add = node => {
                ids.add(node.id);
                node.children.forEach(add);
            };

            add(this.index[id].node);

            return ids;
        },

        // Enquadra o mapa inteiro ou só os itens pedidos.
        fit(ids = null) {
            const items = this.layout.placed.filter(item => !ids || ids.has(item.id));
            const { width, height } = this.$refs.svg.getBoundingClientRect();

            if (!items.length || !width) {
                return;
            }

            const x0 = Math.min(...items.map(i => i.x)) - 30, x1 = Math.max(...items.map(i => i.x + i.w)) + 30;
            const y0 = Math.min(...items.map(i => i.y - i.h / 2)) - 20, y1 = Math.max(...items.map(i => i.y + i.h / 2)) + 20;
            const scale = Math.min(1.2, width / (x1 - x0), height / (y1 - y0));

            this.moveTo(width / 2 - ((x0 + x1) / 2) * scale, height / 2 - ((y0 + y1) / 2) * scale, scale);
        },

        centerOn(id) {
            const item = this.layout.placed.find(i => i.id === id);

            if (!item) {
                return;
            }

            const { width, height } = this.$refs.svg.getBoundingClientRect();
            const scale = Math.max(this.currentScale(), 0.8);

            this.moveTo(width / 2 - (item.x + item.w / 2) * scale, height / 2 - item.y * scale, scale);
        },

        zoomBy(factor) {
            this.svg.transition().duration(200).call(this.zoom.scaleBy, factor);
        },

        currentScale() {
            return Number(/scale\(([\d.]+)\)/.exec(this.transform)?.[1] || 1);
        },

        moveTo(x, y, scale) {
            const { zoomIdentity } = TreeMapD3;
            const motion = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 350;

            this.svg.transition().duration(motion).call(this.zoom.transform, zoomIdentity.translate(x, y).scale(scale));
        },
    },
});
