// "Marcadores Sociais" => "marcadores-sociais": o nome da seção sem acento, caixa e pontuação.
const mdcSectionSlug = title => title.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

app.component('mdc-section-marks', {
    template: $TEMPLATES['mdc-section-marks'],

    // As seções mudam a cada etapa: o formulário é observado enquanto estiver na página.
    mounted() {
        const form = this.$el.parentElement;

        this.observer = new MutationObserver(() => this.mark(form));
        this.observer.observe(form, { childList: true, subtree: true });
        this.mark(form);
    },

    beforeUnmount() {
        this.observer?.disconnect();
    },

    methods: {
        mark(form) {
            for (const card of form.querySelectorAll('.registration-form__section')) {
                const header = card.querySelector('.mc-card__title');
                // só o texto do título; a descrição da seção vem num parágrafo à parte
                const title = [...(header?.childNodes ?? [])].filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join(' ').trim();
                const slug = title ? mdcSectionSlug(title) : '';

                if (card.dataset.mdcSection !== slug) {
                    card.dataset.mdcSection = slug;
                }
            }
        },
    },
});
