app.component('mdc-master-card', {
    template: $TEMPLATES['mdc-master-card'],

    setup() {
        const text = Utils.getTexts('mdc-master-card');
        return { text };
    },

    props: {
        // {name, knownAs, activities (lista ou texto), city, summary, avatar, url}: da vitrine da home
        // (MDC\Services\MasterShowcase) ou da busca (mdc-masters-search)
        master: {
            type: Object,
            required: true,
        },

        // O nível do título com o nome, conforme a página.
        headingTag: {
            type: String,
            default: 'h3',
            validator: (value) => ['h2', 'h3'].includes(value),
        },
    },

    computed: {
        name() {
            return this.master.knownAs || this.master.name;
        },

        // "Coco · Olinda, PE".
        subtitle() {
            const activities = Array.isArray(this.master.activities) ? this.master.activities.join(', ') : this.master.activities;

            return [activities, this.master.city].filter(Boolean).join(' · ');
        },
    },
});
