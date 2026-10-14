app.component('mdc-tree-list', {
    template: $TEMPLATES['mdc-tree-list'],
    emits: ['select'],

    props: {
        // Segmentos da árvore da curadoria: {id, name, enabled, sent, duplicates, children}
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
        showDuplicates: {
            type: Boolean,
            default: true,
        },
        // Ids a destacar, como os encontrados pela busca
        highlight: {
            type: Array,
            default: () => [],
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-tree-list');

        const format = (key, ...values) => MdcTreeText.format(text(key), ...values);

        return { text, format };
    },

    data() {
        return {
            expanded: new Set(),
            // item com o foco do teclado (tabindex 0); os outros ficam fora da tabulação
            focused: null,
        };
    },

    computed: {
        // id => { node, parent, level }
        index() {
            const index = {};
            const visit = (nodes, parent, level) => nodes.forEach(node => {
                index[node.id] = { node, parent, level };
                visit(node.children, node.id, level + 1);
            });

            visit(this.tree, null, 1);

            return index;
        },

        // Itens à vista, na ordem da lista: é por ela que as setas andam.
        rows() {
            const rows = [];
            const visit = (nodes, level) => nodes.filter(node => this.showDisabled || node.enabled).forEach(node => {
                rows.push({ node, level });

                if (this.expanded.has(node.id)) {
                    visit(node.children, level + 1);
                }
            });

            visit(this.tree, 1);

            return rows;
        },

        // Item que recebe o Tab: o do foco ou o escolhido, se estiver à vista; senão, o primeiro.
        current() {
            const visible = id => this.rows.some(row => row.node.id === id);

            return [this.focused, this.selected].find(id => id !== null && visible(id)) ?? this.rows[0]?.node.id ?? null;
        },
    },

    watch: {
        selected(id) {
            if (id) {
                this.reveal(id);
            }
        },
    },

    // Aberta com um item já escolhido (pelo mapa ou pela busca), a lista abre o caminho até ele.
    mounted() {
        if (this.selected) {
            this.reveal(this.selected);
        }
    },

    methods: {
        kids(node) {
            return node.children.filter(child => this.showDisabled || child.enabled);
        },

        reveal(id) {
            for (let parent = this.index[id]?.parent; parent; parent = this.index[parent].parent) {
                this.expanded.add(parent);
            }

            this.focused = id;
            this.$nextTick(() => this.$refs['item-' + id]?.[0]?.scrollIntoView({ block: 'nearest' }));
        },

        choose(node) {
            this.focused = node.id;
            this.$emit('select', node.id);
        },

        toggle(node) {
            this.expanded.has(node.id) ? this.expanded.delete(node.id) : this.expanded.add(node.id);
        },

        focus(id) {
            this.focused = id;
            this.$nextTick(() => this.$refs['item-' + id]?.[0]?.focus());
        },

        // Teclado do padrão de árvore (WAI-ARIA): setas, Home, End, Enter e espaço.
        keydown(event, node) {
            const position = this.rows.findIndex(row => row.node.id === node.id);
            const open = this.expanded.has(node.id);
            const children = this.kids(node);
            const keys = {
                ArrowDown: () => this.rows[position + 1] && this.focus(this.rows[position + 1].node.id),
                ArrowUp: () => this.rows[position - 1] && this.focus(this.rows[position - 1].node.id),
                ArrowRight: () => (children.length && !open ? this.expanded.add(node.id) : open && this.focus(children[0].id)),
                ArrowLeft: () => (open ? this.expanded.delete(node.id) : this.index[node.id].parent && this.focus(this.index[node.id].parent)),
                Home: () => this.rows.length && this.focus(this.rows[0].node.id),
                End: () => this.rows.length && this.focus(this.rows[this.rows.length - 1].node.id),
                Enter: () => this.choose(node),
                ' ': () => this.choose(node),
            };

            if (keys[event.key]) {
                event.preventDefault();
                keys[event.key]();
            }
        },
    },
});
