app.component('mdc-festivity-masters', {
    template: $TEMPLATES['mdc-festivity-masters'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-masters');
        return { text };
    },

    data() {
        return {
            query: '',
            results: [],
            selected: [],
            // Vínculos gravados que a busca não traz (quem perdeu o selo): seguem gravados, sem aparecer.
            hidden: [],
            searched: false,
            timer: null,
            loading: false,
            failed: false,
            announcement: '',
        };
    },

    computed: {
        errors() {
            return this.entity.__validationErrors?.mdcFestejoMestres || [];
        },
    },

    // Lê os vínculos gravados antes de deixar adicionar ou remover.
    async created() {
        const ids = (this.entity.mdcFestejoMestres || '').split('|').filter(Boolean).map(Number);

        if (!ids.length) {
            return;
        }

        this.loading = true;

        try {
            this.selected = await this.find({ id: `IN(${ids.join(',')})` });
            const found = this.selected.map((master) => master.id);
            this.hidden = ids.filter((id) => !found.includes(id));
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },

    methods: {
        // Só quem tem o selo do programa; a busca por nome procura também o nome artístico.
        find(query) {
            return new API('agent').find({
                '@select': 'id,name,mdcNomeArtistico,mdcCidade',
                '@seals': String($MAPAS.config.mdcFestivity.sealId ?? 0),
                ...query,
            });
        },

        labelOf(master) {
            const name = master.mdcNomeArtistico || master.name;
            return master.mdcCidade ? `${name} · ${master.mdcCidade}` : name;
        },

        search() {
            clearTimeout(this.timer);
            this.searched = false;

            if (this.query.trim().length < 3) {
                this.results = [];
                return;
            }

            this.timer = setTimeout(async () => {
                const ids = this.selected.map((master) => master.id);
                const found = await this.find({ '@keyword': `%${this.query.trim()}%`, '@limit': 10, '@order': 'name ASC' });
                this.results = found.filter((master) => !ids.includes(master.id));
                this.searched = true;
            }, 300);
        },

        save() {
            if (this.loading || this.failed) {
                return;
            }

            const ids = [...this.selected.map((master) => master.id), ...this.hidden];
            this.entity.mdcFestejoMestres = ids.length ? `|${ids.join('|')}|` : '';
        },

        // O foco volta à busca, e o leitor de tela anuncia quem entrou.
        add(master) {
            this.selected.push(master);
            this.save();
            this.query = '';
            this.results = [];
            this.searched = false;
            this.announcement = this.text('adicionado').replace('%s', this.labelOf(master));
            this.$nextTick(() => this.$refs.search?.focus());
        },

        // O foco vai para o remover vizinho (o seguinte, ou o anterior no fim da lista), ou para a busca na última.
        remove(id) {
            const index = this.selected.findIndex((master) => master.id === id);
            const removed = this.selected[index];
            this.selected = this.selected.filter((master) => master.id !== id);
            this.save();
            this.announcement = this.text('removido').replace('%s', this.labelOf(removed));

            const next = this.selected[Math.min(index, this.selected.length - 1)];
            this.$nextTick(() => (next ? this.$refs['remove-' + next.id]?.[0] : this.$refs.search)?.focus());
        },
    },
});
