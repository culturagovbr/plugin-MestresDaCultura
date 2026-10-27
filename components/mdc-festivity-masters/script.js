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
            searched: false,
            timer: null,
        };
    },

    computed: {
        errors() {
            return this.entity.__validationErrors?.mdcFestejoMestres || [];
        },
    },

    async created() {
        const ids = (this.entity.mdcFestejoMestres || '').split('|').filter(Boolean);

        if (ids.length) {
            this.selected = await this.find({ id: `IN(${ids.join(',')})` });
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
            const ids = this.selected.map((master) => master.id);
            this.entity.mdcFestejoMestres = ids.length ? `|${ids.join('|')}|` : '';
        },

        add(master) {
            this.selected.push(master);
            this.save();
            this.query = '';
            this.results = [];
            this.searched = false;
        },

        remove(id) {
            this.selected = this.selected.filter((master) => master.id !== id);
            this.save();
        },
    },
});
