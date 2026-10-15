app.component('mdc-moderation-notice', {
    template: $TEMPLATES['mdc-moderation-notice'],

    props: {
        // o resultado da moderação que o plugin monta: devolvido (MDC\Services\Moderation::pendingView) ou aprovado
        moderation: {
            type: Object,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-moderation-notice');

        return { text };
    },

    computed: {
        approved() {
            return this.moderation.status === 'approved';
        },

        // Os recusados primeiro.
        fields() {
            return [...(this.moderation.fields ?? [])].sort((a, b) => (a.result === 'invalid' ? 0 : 1) - (b.result === 'invalid' ? 0 : 1));
        },
    },

    methods: {
        title(field) {
            return field.section || field.label;
        },
    },
});
