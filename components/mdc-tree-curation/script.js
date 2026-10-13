app.component('mdc-tree-curation', {
    template: $TEMPLATES['mdc-tree-curation'],

    setup() {
        const text = Utils.getTexts('mdc-tree-curation');
        const messages = useMessages();

        return { text, messages };
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
            // ação de curadoria em andamento
            busy: false,
        };
    },

    computed: {
        root() {
            return { id: 0, name: this.text('raiz'), enabled: true, sent: 0, drafts: 0, children: this.tree || [] };
        },

        // id => { node, parent, path }: o caminho são os nomes da raiz até o item.
        index() {
            const index = { 0: { node: this.root, parent: null, path: [] } };
            const visit = (parent, path) => parent.children.forEach(node => {
                index[node.id] = { node, parent, path: [...path, node.name] };
                visit(node, index[node.id].path);
            });

            visit(this.root, []);

            return index;
        },

        current() {
            return this.selected === null ? null : this.index[this.selected] || null;
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

        /**
         * Envia uma ação de curadoria (rotas `mdc/curation*`) e relê a árvore; a seleção e os ramos abertos
         * continuam. A recusa vem com a mensagem da regra, mostrada como está.
         *
         * @return a resposta da rota, ou null quando a ação não foi feita
         */
        async act(action, data, success) {
            this.busy = true;

            try {
                const response = await fetch(Utils.createUrl('mdc', action), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                });
                const result = await response.json().catch(() => null);

                if (!response.ok) {
                    this.messages.error(result?.data || this.text('erro'));
                    return null;
                }

                await this.load();
                this.messages.success(success);

                return result;
            } catch {
                this.messages.error(this.text('erro'));
                return null;
            } finally {
                this.busy = false;
            }
        },

        reorder(offset) {
            const name = this.current.node.name;

            this.act('curationReorder', { id: this.selected, offset }, this.text(offset < 0 ? 'subiu' : 'desceu').replace('%s', name));
        },
    },
});
