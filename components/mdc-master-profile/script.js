app.component('mdc-master-profile', {
    template: $TEMPLATES['mdc-master-profile'],

    props: {
        // o que o plugin monta sobre o mestre aprovado (MDC\Services\MasterProfile)
        profile: {
            type: Object,
            required: true,
        },
        // a parte da página de agente: 'header', 'certificate' ou 'acting'
        part: {
            type: String,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-master-profile');
        const format = (key, ...values) => values.reduce((s, v) => s.replace('%s', v), text(key));

        return { text, format };
    },

    computed: {
        // "Bilros, Coco · Olinda, PE"
        summary() {
            return [this.profile.activities.join(', '), this.profile.city].filter(Boolean).join(' · ');
        },
    },
});
