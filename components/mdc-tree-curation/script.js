app.component('mdc-tree-curation', {
    template: $TEMPLATES['mdc-tree-curation'],

    setup() {
        const text = Utils.getTexts('mdc-tree-curation');

        return { text };
    },

    data() {
        return {
            tree: null,
            loading: true,
            failed: false,
            selected: null,
            showDisabled: true,
            // ids encontrados pela busca, destacados no mapa
            matches: [],
        };
    },

    computed: {
        // id => { node, path }: o caminho são os nomes da raiz até o item.
        index() {
            const index = {};
            const visit = (nodes, path) => nodes.forEach(node => {
                index[node.id] = { node, path: [...path, node.name] };
                visit(node.children, index[node.id].path);
            });

            visit(this.tree || [], []);

            return index;
        },

        current() {
            return this.index[this.selected] || null;
        },
    },

    created() {
        this.load();
    },

    methods: {
        // Vindo da busca: escolhe o item, ou leva o mapa até ele quando já é o escolhido.
        focus(id) {
            if (this.selected === id) {
                this.$refs.map.reveal(id);
            } else {
                this.selected = id;
            }
        },

        async load() {
            try {
                const response = await fetch(Utils.createUrl('mdc', 'curationTree'), { headers: { Accept: 'application/json' } });

                if (!response.ok) {
                    throw new Error(response.status);
                }

                this.tree = await response.json();
            } catch {
                this.failed = true;
            } finally {
                this.loading = false;
            }
        },
    },
});
