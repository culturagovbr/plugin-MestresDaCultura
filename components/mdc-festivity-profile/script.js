app.component('mdc-festivity-profile', {
    template: $TEMPLATES['mdc-festivity-profile'],

    props: {
        // "header", "main" ou "aside": o pedaço da página de evento do core onde o bloco entra
        part: {
            type: String,
            required: true,
        },
        // MDC\Services\FestivityProfile::of(), só com o que é público
        profile: {
            type: Object,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-profile');
        return { text };
    },

    computed: {
        classification() {
            return [this.profile.segment, ...this.profile.typologies].filter(Boolean).join(' · ');
        },

        history() {
            const parts = [];
            if (this.profile.firstYear) {
                parts.push(this.format('desde', this.profile.firstYear));
            }
            if (this.profile.editions) {
                parts.push(this.format('edicoes', this.profile.editions));
            }
            return parts.join(' · ');
        },
    },

    methods: {
        // Troca cada `%s` do texto pelo próximo valor.
        format(key, ...values) {
            return values.reduce((s, v) => s.replace('%s', v), this.text(key));
        },
    },
});
