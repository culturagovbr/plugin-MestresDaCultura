app.component('mdc-age', {
    template: $TEMPLATES['mdc-age'],

    props: {
        // o valor do campo de data: McDate, depois de lido do servidor, ou "1958-03-20"
        value: {
            required: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-age');

        return { text };
    },

    computed: {
        // Anos completos hoje; nada para data vazia, inválida ou futura.
        age() {
            const date = this.value?._date instanceof Date ? this.value._date : new Date(`${String(this.value ?? '').slice(0, 10)}T00:00:00`);

            if (!this.value || isNaN(date.getTime())) {
                return null;
            }

            const today = new Date();
            const birthdayPassed = today.getMonth() > date.getMonth() || (today.getMonth() === date.getMonth() && today.getDate() >= date.getDate());
            const age = today.getFullYear() - date.getFullYear() - (birthdayPassed ? 0 : 1);

            return age >= 0 ? age : null;
        },
    },
});
