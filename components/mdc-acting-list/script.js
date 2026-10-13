// Um nível da árvore de atuação; a seleção mora em `mdc-acting-field`.
app.component('mdc-acting-list', {
    template: $TEMPLATES['mdc-acting-list'],

    inject: ['acting'],

    props: {
        parent: {
            type: Object,
            required: true,
        },
    },

    computed: {
        otherId() {
            return 'mdc-acting-other-' + this.acting.uid + '-' + this.parent.id;
        },
    },
});
