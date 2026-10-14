// Quantos cartões aparecem de cada vez, na fila e nos tratados.
const MDC_OTHERS_PAGE = 20;

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
        return {
            entries: [],
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
            shownPending: MDC_OTHERS_PAGE,
            shownDecided: MDC_OTHERS_PAGE,
            // os tratados só são desenhados com a lista aberta
            decidedOpen: false,
        };
    },

    computed: {
        pending() {
            return this.entries.filter(entry => !entry.decision);
        },

        decided() {
            return this.entries.filter(entry => entry.decision);
        },

        foundPending() {
            return this.pending.filter(entry => this.matches(entry));
        },

        foundDecided() {
            return this.decided.filter(entry => this.matches(entry));
        },

        // Contagem por decisão, já com a busca aplicada, para os botões do filtro.
        decisionCounts() {
            const counts = { '': this.foundDecided.length, created: 0, associated: 0, archived: 0 };
            this.foundDecided.forEach(entry => counts[entry.decision.type]++);

            return counts;
        },

        filteredDecided() {
            return this.decisionFilter ? this.foundDecided.filter(entry => entry.decision.type === this.decisionFilter) : this.foundDecided;
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
        pending(pending) {
            this.$emit('count', pending.length);
        },

        // Outra busca ou outro filtro recomeça a contagem do "mostrar mais".
        search() {
            this.shownPending = MDC_OTHERS_PAGE;
            this.shownDecided = MDC_OTHERS_PAGE;
        },

        decisionFilter() {
            this.shownDecided = MDC_OTHERS_PAGE;
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            try {
                this.entries = await this.curation.read('curationOthers');
            } catch {
                this.failed = true;
            } finally {
                this.loading = false;
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

        // A busca olha o texto, o lugar onde foi escrito e, nos tratados, o item escolhido e o motivo.
        matches(entry) {
            const query = MdcTreeText.comparable(this.search);

            if (!query) {
                return true;
            }

            const fields = [entry.text, this.placeOf(entry)];

            if (entry.decision) {
                fields.push(entry.decision.node ? this.pathOf(entry.decision.node) : '', entry.decision.note || '');
            }

            return fields.some(field => MdcTreeText.comparable(field).includes(query));
        },

        showMore(list) {
            this[list] += MDC_OTHERS_PAGE;
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
                await this.load();
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
