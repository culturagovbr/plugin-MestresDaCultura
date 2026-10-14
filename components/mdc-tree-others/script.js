// Quantos cartões vêm por página, na fila e nos tratados.
const MDC_OTHERS_PAGE = 20;
// Espera depois da digitação antes de buscar, como nas listas do core.
const MDC_OTHERS_DEBOUNCE = 300;

app.component('mdc-tree-others', {
    template: $TEMPLATES['mdc-tree-others'],
    emits: ['count'],

    // A árvore, as ações e a navegação até o item vêm da página (mdc-tree-curation).
    inject: ['curation'],

    setup() {
        const text = Utils.getTexts('mdc-tree-others');

        const format = (key, ...values) => MdcTreeText.format(text(key), ...values);

        return { text, format };
    },

    data() {
        const list = () => ({ items: [], metadata: null, page: 1, loading: false });

        return {
            loading: true,
            failed: false,
            // o texto da fila sendo tratado num modal
            active: null,
            name: '',
            note: '',
            query: '',
            chosen: null,
            limit: 30,
            // busca na fila (texto, lugar, item ou motivo) e filtro dos tratados por decisão
            search: '',
            decisionFilter: '',
            // as duas listas, paginadas no servidor; os tratados só são buscados com a lista aberta
            lists: { pending: list(), decided: list() },
            decidedOpen: false,
        };
    },

    computed: {
        // Totais sem busca nem filtro, que vêm em toda página.
        totals() {
            return this.lists.pending.metadata?.totals || this.lists.decided.metadata?.totals || { pending: 0, decided: 0 };
        },

        // Contagem por decisão, já com a busca aplicada, para os botões do filtro.
        decisionCounts() {
            const counts = this.lists.decided.metadata?.counts || { created: 0, associated: 0, archived: 0 };

            return { '': counts.created + counts.associated + counts.archived, ...counts };
        },

        // Onde um item novo pode entrar: ativo e com nível abaixo dele; a raiz faz dele um segmento.
        places() {
            return this.targets(({ node, path }) => node.enabled && path.length < 4);
        },

        // A que item um texto pode ser associado: qualquer item ativo.
        items() {
            return this.targets(({ node }) => node.id && node.enabled);
        },
    },

    watch: {
        'totals.pending'(count) {
            this.$emit('count', count);
        },

        search() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => this.refresh(), MDC_OTHERS_DEBOUNCE);
        },

        decisionFilter() {
            this.fetch('decided');
        },
    },

    async created() {
        // Um cancelamento por lista, fora dos dados reativos.
        this.aborts = {};
        await this.fetch('pending');
        this.loading = false;
    },

    methods: {
        /**
         * Busca uma página de uma das listas; a página 1 recomeça a lista, as outras se juntam ao fim.
         * Uma resposta que chega depois de outra busca é descartada.
         */
        async fetch(name, page = 1) {
            const list = this.lists[name];
            const query = { status: name, '@limit': MDC_OTHERS_PAGE, '@page': page };

            if (this.search.trim()) {
                query['@keyword'] = this.search.trim();
            }

            if (name === 'decided' && this.decisionFilter) {
                query.decision = this.decisionFilter;
            }

            this.aborts[name]?.abort();
            const abort = this.aborts[name] = new AbortController();
            list.loading = true;

            try {
                const { items, metadata } = await this.curation.readPage('curationOthers', query, abort.signal);

                list.items = page === 1 ? items : [...list.items, ...items];
                list.metadata = metadata;
                list.page = page;
                list.loading = false;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.failed = true;
                    list.loading = false;
                }
            }
        },

        // Outra busca ou uma decisão tomada: as listas recomeçam da primeira página.
        refresh() {
            this.fetch('pending');

            if (this.decidedOpen) {
                this.fetch('decided');
            } else {
                this.lists.decided.metadata = null;
            }
        },

        hasMore(name) {
            const { metadata, page } = this.lists[name];

            return !!metadata && page < metadata.numPages;
        },

        loadMore(name) {
            if (!this.lists[name].loading) {
                this.fetch(name, this.lists[name].page + 1);
            }
        },

        toggleDecided(open) {
            this.decidedOpen = open;

            if (open && !this.lists.decided.metadata) {
                this.fetch('decided');
            }
        },

        targets(accept) {
            return Object.values(this.curation.index)
                .filter(accept)
                .map(({ node, path }) => ({ id: node.id, place: node.id ? path.join(' › ') : this.text('vira segmento'), key: MdcTreeText.comparable(path.join(' ')) }));
        },

        // O escolhido e as sugestões ficam sempre no topo; abaixo, o que a busca encontrar.
        found(targets) {
            const query = MdcTreeText.comparable(this.query);
            const pinned = [this.chosen, ...(this.active?.suggestions || [])];
            const rank = target => (pinned.includes(target.id) ? pinned.indexOf(target.id) : pinned.length);

            return targets
                .filter(target => pinned.includes(target.id) || !query || target.key.includes(query))
                .sort((a, b) => rank(a) - rank(b) || a.place.localeCompare(b.place, 'pt-BR'));
        },

        placeOf(entry) {
            return entry.parent === null ? this.text('geral') : this.curation.index[entry.parent]?.path.join(' › ') || '';
        },

        pathOf(id) {
            return this.curation.index[id]?.path.join(' › ') || this.text('item removido');
        },

        when(timestamp) {
            return timestamp ? new Date(timestamp.replace(' ', 'T')).toLocaleDateString('pt-BR') : '';
        },

        open(modal, entry) {
            this.active = entry;
            this.name = entry.text;
            this.note = '';
            this.chosen = modal === 'create' && entry.parent !== null && this.places.some(place => place.id === entry.parent) ? entry.parent : null;
            this.query = '';
            this.$refs[modal].open();
        },

        async run(modal, action, data, success) {
            modal.loading(true);
            const done = await this.curation.act(action, { parent: this.active.parent, key: this.active.key, ...data }, success);
            modal.loading(false);

            if (done) {
                modal.close();
                this.refresh();
            }
        },

        create(modal) {
            const place = this.chosen ? this.pathOf(this.chosen) : this.text('raiz');

            this.run(modal, 'curationOtherCreate', { name: this.name, place: this.chosen || null }, this.format('criado aviso', this.name.trim(), place));
        },

        associate(modal) {
            this.run(modal, 'curationOtherAssociate', { node: this.chosen }, this.format('associado aviso', this.active.text, this.pathOf(this.chosen)));
        },

        archive(modal) {
            this.run(modal, 'curationOtherArchive', { note: this.note }, this.format('arquivado aviso', this.active.text));
        },
    },
});
