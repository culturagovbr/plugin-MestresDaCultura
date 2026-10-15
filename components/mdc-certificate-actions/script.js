app.component('mdc-certificate-actions', {
    template: $TEMPLATES['mdc-certificate-actions'],

    setup() {
        const text = Utils.getTexts('mdc-certificate-actions');

        return { text };
    },

    methods: {
        back() {
            window.history.back();
        },

        // A folha de impressão do tema deixa só o certificado na página.
        print() {
            window.print();
        },
    },
});
