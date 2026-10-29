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
            formUrl: Utils.createUrl('mdc', 'festivity'),
        };
    },

    async created() {
        const value = String(this.entity[this.prop] ?? '').trim();

        if (/^\d+$/.test(value)) {
            this.chosen = await new API('event').findOne(Number(value), 'id,name,singleUrl,mdcFestejoCidade,mdcFestejoUf').catch(() => null);
        } else {
            this.legacy = value;
        }
    },

    methods: {
        placeOf(festivity) {
            return [festivity.mdcFestejoCidade, festivity.mdcFestejoUf].filter(Boolean).join(', ');
        },

        // Só os do Calendário (publicados e com o selo dos festejos), como o servidor confere (FestivityField::isValid).
        search() {
            clearTimeout(this.timer);
            this.searched = false;

            if (this.query.trim().length < 3) {
                this.results = [];
                return;
            }

            this.timer = setTimeout(async () => {
                this.results = await new API('event').find({
                    '@select': 'id,name,singleUrl,mdcFestejoCidade,mdcFestejoUf',
                    '@keyword': `%${this.query.trim()}%`,
                    '@seals': String($MAPAS.config.mdcFestivityField?.sealId ?? 0),
                    status: 'EQ(1)',
                    '@order': 'name ASC',
                    '@limit': 10,
                });
                this.searched = true;
            }, 300);
        },

        choose(festivity) {
            this.chosen = festivity;
            this.legacy = '';
            this.query = '';
            this.results = [];
            this.searched = false;
            this.$emit('change', String(festivity.id));
        },

        clear() {
            this.chosen = null;
            this.$emit('change', '');
        },
    },
});
