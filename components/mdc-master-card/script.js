app.component('mdc-master-card', {
    template: $TEMPLATES['mdc-master-card'],

    props: {
        // {name, knownAs, activities (lista ou texto), city, summary, avatar, url}: da vitrine da home
        // (MDC\Services\MasterShowcase) ou da busca (mdc-masters-search)
        master: {
            type: Object,
            required: true,
        },
    },

    computed: {
        // "Coco · Olinda, PE".
        subtitle() {
            const activities = Array.isArray(this.master.activities) ? this.master.activities.join(', ') : this.master.activities;

            return [activities, this.master.city].filter(Boolean).join(' · ');
        },
    },
});
