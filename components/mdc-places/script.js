app.component('mdc-places', {
    template: $TEMPLATES['mdc-places'],

    setup() {
        const text = Utils.getTexts('mdc-places');
        return { text };
    },

    data() {
        const config = $MAPAS.config.mdcPlaces;

        return {
            places: config.places,
            formUrl: config.formUrl,
            // os erros de exclusão, por local
            errors: {},
        };
    },

    methods: {
        format(key, value) {
            return this.text(key).replace('%s', value);
        },

        addressOf(place) {
            const city = [place.city, place.uf].filter(Boolean).join('/');

            return [place.address, city, place.cep].filter(Boolean).join(' · ') || this.text('sem endereco');
        },

        // O que a janela (mdc-place-editor) gravou: o local editado, ou o novo, que entra em rascunho e sem festejo.
        updated(entity) {
            let place = this.places.find((item) => item.id === entity.id);

            if (!place) {
                place = { id: entity.id, status: 0, festivities: [] };
                this.places.push(place);
            }

            Object.assign(place, {
                name: entity.name,
                address: [entity.En_Nome_Logradouro, entity.En_Num, entity.En_Bairro].filter(Boolean).join(', '),
                city: entity.En_Municipio || '',
                uf: entity.En_Estado || '',
                cep: entity.En_CEP || '',
            });
            this.places.sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
        },

        // O servidor barra a exclusão do local de um festejo (Plugin, DELETE(space.single):before); o erro diz quais.
        async remove(place) {
            delete this.errors[place.id];

            try {
                await new API('space').getEntityInstance(place.id).delete();
                this.places = this.places.filter((item) => item.id !== place.id);
            } catch (error) {
                this.errors[place.id] = Object.values(error?.data || {}).flat().join(' ') || this.text('falha');
            }
        },
    },
});
