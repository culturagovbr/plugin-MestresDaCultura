app.component('mdc-festivity-card', {
    template: $TEMPLATES['mdc-festivity-card'],

    props: {
        // {name, url, avatar, agenda, classification, details}, montado pela busca (mdc-festivities-search)
        festivity: {
            type: Object,
            required: true,
        },
    },
});
