app.component('mdc-tree-curation', {
    template: $TEMPLATES['mdc-tree-curation'],

    setup() {
        const text = Utils.getTexts('mdc-tree-curation');
        const messages = useMessages();
        // Sem cache: a árvore e a fila mudam a cada ação.
        const api = new API('mdc', 'curation', { cacheMode: 'no-store' });

        return { text, messages, api };
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
            showDuplicates: true,
            // Mapa ou lista; a lista, acessível por teclado, é a padrão em telas estreitas.
            view: matchMedia('(max-width: 720px)').matches ? 'lista' : 'mapa',
            // ids encontrados pela busca, destacados no mapa
            matches: [],
            // ação de curadoria em andamento
            busy: false,
            // textos da fila "Não encontrou?" esperando decisão
            pendingOthers: 0,
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
            return this.api.createUrl('curationExport');
        },

        current() {
            return this.selected === null ? null : this.index[this.selected] || null;
        },
    },

    created() {
        this.load();
        this.countOthers();
    },

    methods: {
        // Vindo da busca: escolhe o item, ou leva o mapa até ele quando já é o escolhido.
        focus(id) {
            if (this.selected === id) {
                (this.$refs.map || this.$refs.list).reveal(id);
            } else {
                this.selected = id;
            }
        },

        // Conta a fila para o rótulo da aba; depois de aberta, a fila atualiza a contagem.
        async countOthers() {
            try {
                this.pendingOthers = (await this.read('curationOthers')).filter(entry => !entry.decision).length;
            } catch {
                this.pendingOthers = 0;
            }
        },

        // Vindo da fila: volta para a aba do mapa e leva ao item.
        goTo(id) {
            this.$refs.tabs.selectTab('mapa');
            this.$nextTick(() => this.focus(id));
        },

        async load() {
            try {
                this.tree = await this.read('curationTree');
            } catch {
                this.failed = true;
            } finally {
                this.loading = false;
            }
        },

        // Histórico de um item (lista vazia se não deu para carregar).
        async history(id) {
            try {
                return await this.read('curationHistory', { id });
            } catch {
                return [];
            }
        },

        // Lê uma rota da curadoria; falha (inclusive recusa) vira erro, para quem chama decidir o que mostrar.
        async read(route, query) {
            const response = await this.api.GET(this.api.createUrl(route, query));

            if (!response.ok) {
                throw new Error(response.status);
            }

            return response.json();
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
                const response = await this.api.POST(this.api.createUrl(action), data);
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

        async markLegitimate() {
            return !!(await this.act('curationLegitimate', { id: this.selected }, this.format('legitima', this.current.node.name)));
        },

        async unmarkLegitimate() {
            return !!(await this.act('curationLegitimateUndo', { id: this.selected }, this.format('legitima desfeita', this.current.node.name)));
        },

        async markEquivalent(referenceId) {
            const reference = this.index[referenceId].path.join(' › ');

            return !!(await this.act('curationEquivalence', { id: this.selected, to: referenceId }, this.format('equivalente', this.current.node.name, reference)));
        },

        async undoEquivalence() {
            return !!(await this.act('curationEquivalenceUndo', { id: this.selected }, this.format('equivalencia desfeita', this.current.node.name)));
        },

        async disable() {
            return !!(await this.act('curationDisable', { id: this.selected }, this.format('desativado', this.current.node.name)));
        },

        async enable() {
            return !!(await this.act('curationEnable', { id: this.selected }, this.format('reativado', this.current.node.name)));
        },

        format(key, ...values) {
            return MdcTreeText.format(this.text(key), ...values);
        },
    },
});
