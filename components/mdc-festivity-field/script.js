app.component('mdc-festivity-field', {
    template: $TEMPLATES['mdc-festivity-field'],

    emits: ['change'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        prop: {
            type: String,
            required: true,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        // O id que o `<label for>` do entity-field usa.
        fieldId: {
            type: String,
            default: '',
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-field');
        return { text };
    },

    data() {
        return {
            chosen: null,
            // a resposta em texto, de antes da busca (MDC\Services\FestivityField)
            legacy: '',
            query: '',
            results: [],
            searched: false,
            timer: null,
            announcement: '',
            // o festejo escolhido antes saiu do Calendário (despublicado): o id segue gravado até a pessoa trocar
            gone: false,
            searchId: 0,
            searchFailed: false,
            formUrl: Utils.createUrl('mdc', 'festivity'),
        };
    },

    async created() {
        const value = String(this.entity[this.prop] ?? '').trim();

        if (/^\d+$/.test(value)) {
            const found = await new API('event').findOne(Number(value), 'id,name,singleUrl,mdcFestejoCidade,mdcFestejoUf').catch(() => null);

            // Só mostra o festejo se ele ainda for o escolhido.
            if (String(this.entity[this.prop] ?? '').trim() === value) {
                this.chosen = found;
                this.gone = !found;
            }
        } else {
            this.legacy = value;
        }
    },

    methods: {
        placeOf(festivity) {
            return [festivity.mdcFestejoCidade, festivity.mdcFestejoUf].filter(Boolean).join(', ');
        },

        // Só os do Calendário (publicados e com o selo dos festejos); descarta a resposta de uma busca anterior ou que
        // chega depois da escolha.
        search() {
            clearTimeout(this.timer);
            const id = ++this.searchId;
            this.searched = false;
            this.searchFailed = false;

            if (this.query.trim().length < 3) {
                this.results = [];
                return;
            }

            this.timer = setTimeout(async () => {
                try {
                    const found = await new API('event').find({
                        '@select': 'id,name,singleUrl,mdcFestejoCidade,mdcFestejoUf',
                        '@keyword': `%${this.query.trim()}%`,
                        '@seals': String($MAPAS.config.mdcFestivityField?.sealId ?? 0),
                        status: 'EQ(1)',
                        '@order': 'name ASC',
                        '@limit': 10,
                    });

                    if (id === this.searchId) {
                        this.results = found;
                        this.searched = true;
                    }
                } catch {
                    if (id === this.searchId) {
                        this.searchFailed = true;
                    }
                }
            }, 300);
        },

        choose(festivity) {
            this.searchId++;
            this.gone = false;
            this.chosen = festivity;
            this.legacy = '';
            this.query = '';
            this.results = [];
            this.searched = false;
            this.$emit('change', String(festivity.id));
            // O foco vai para o festejo escolhido, e o leitor de tela anuncia a escolha.
            this.announcement = this.text('escolhido').replace('%s', festivity.name);
            this.$nextTick(() => this.$refs.chosen?.focus());
        },

        clear() {
            this.chosen = null;
            this.$emit('change', '');
            this.announcement = '';
            this.$nextTick(() => this.$refs.search?.focus());
        },
    },
});
