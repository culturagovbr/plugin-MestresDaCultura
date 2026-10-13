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
        };
    },

    computed: {
        pending() {
            return this.entries.filter(entry => !entry.decision);
        },

        decided() {
            return this.entries.filter(entry => entry.decision);
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

        decisionOf(entry) {
            const { type, node, note } = entry.decision;

            if (type === 'archived') {
                return note ? this.format('arquivado motivo', note) : this.text('arquivado');
            }

            return this.format(type === 'created' ? 'criado' : 'associado', node ? this.pathOf(node) : this.text('item removido'));
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
