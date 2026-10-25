app.component('mdc-panel-summary', {
    template: $TEMPLATES['mdc-panel-summary'],

    props: {
        // os blocos que o plugin monta para o papel de quem abre o painel (MDC\Services\PanelSummary::for)
        summary: {
            type: Object,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-panel-summary');

        return { text };
    },

    computed: {
        moderation() {
            return this.summary.moderation;
        },

        management() {
            return this.summary.management;
        },

        // Na ordem do caminho do cadastro, como a coluna da lista (MDC\Services\Moderation::SITUATIONS).
        situations() {
            return Object.entries(this.management?.labels ?? {}).map(([slug, label]) => ({ slug, label, count: this.management.counts[slug] ?? 0 }));
        },
    },
});
