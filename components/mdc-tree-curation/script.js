app.component('mdc-tree-curation', {
    template: $TEMPLATES['mdc-tree-curation'],

    setup() {
        const text = Utils.getTexts('mdc-tree-curation');
        const messages = useMessages();

        return { text, messages };
    },

    // O painel do item chama as ações por aqui.
    provide() {
        return { curation: this };
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

        exportUrl() {
            return Utils.createUrl('mdc', 'curationExport');
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

        // Cada ação devolve se deu certo, para o modal decidir se fecha.
        async reorder(offset) {
            const name = this.current.node.name;

            return !!(await this.act('curationReorder', { id: this.selected, offset }, this.format(offset < 0 ? 'subiu' : 'desceu', name)));
        },

        async rename(name) {
            const previous = this.current.node.name;

            return !!(await this.act('curationRename', { id: this.selected, name }, this.format('renomeado', previous, name.trim())));
        },

        async add(name) {
            const parent = this.current.node;
            const result = await this.act('curationAdd', { parent: parent.id || null, name }, this.format('adicionado', name.trim(), parent.name));

            if (result?.id) {
                this.selected = result.id;
            }

            return !!result;
        },

        async move(parentId) {
            const name = this.current.node.name;
            const place = parentId ? this.index[parentId].path.join(' › ') : this.text('raiz');

            return !!(await this.act('curationMove', { id: this.selected, parent: parentId || null }, this.format('movido', name, place)));
        },

        async disable() {
            return !!(await this.act('curationDisable', { id: this.selected }, this.format('desativado', this.current.node.name)));
        },

        async enable() {
            return !!(await this.act('curationEnable', { id: this.selected }, this.format('reativado', this.current.node.name)));
        },

        // substitui cada `%s` do texto pelo próximo argumento
        format(key, ...values) {
            return values.reduce((s, v) => s.replace('%s', v), this.text(key));
        },
    },
});
