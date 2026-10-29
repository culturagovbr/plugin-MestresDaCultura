app.component('mdc-festivity-years', {
    template: $TEMPLATES['mdc-festivity-years'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        // o campo dos anos de uma fonte de recursos (`{fonte}Anos`)
        prop: {
            type: String,
            required: true,
        },
        required: {
            type: Boolean,
            default: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-years');
        return { text };
    },

    data() {
        return {
            // Quantos anos para trás aparecem.
            shown: 15,
        };
    },

    computed: {
        // Os anos gravados, venham dos botões ("2023, 2024") ou do texto livre de antes ("2023 e 2024").
        selected() {
            return (String(this.entity[this.prop] || '').match(/\d{4}/g) || []).map(Number);
        },

        // O ano da primeira realização (etapa 1), o limite dos "Anos anteriores".
        first() {
            const current = new Date().getFullYear();
            const first = Number(this.entity.mdcFestejoPrimeiroAno);
            return first >= 1000 && first <= current ? first : current - 14;
        },

        // Os últimos 15 anos, mais 15 a cada "Anos anteriores", até o da primeira realização. Um ano gravado fora da
        // faixa continua à vista, para poder ser desmarcado.
        years() {
            const current = new Date().getFullYear();
            const from = Math.max(this.first, current - this.shown + 1);
            const years = new Set(this.selected);

            for (let year = current; year >= from; year--) {
                years.add(year);
            }

            return [...years].sort((a, b) => b - a);
        },

        hasEarlier() {
            return new Date().getFullYear() - this.shown + 1 > this.first;
        },

        errors() {
            return this.entity.__validationErrors?.[this.prop] || [];
        },
    },

    methods: {
        toggle(year) {
            const selected = this.selected.includes(year)
                ? this.selected.filter((item) => item !== year)
                : [...this.selected, year];

            this.entity[this.prop] = selected.sort((a, b) => a - b).join(', ');
        },
    },
});
