app.component('mdc-place-editor', {
    template: $TEMPLATES['mdc-place-editor'],

    emits: ['saved'],

    setup() {
        const text = Utils.getTexts('mdc-place-editor');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        return {
            place: null,
            saving: false,
            saved: false,
        };
    },

    methods: {
        // Abre o local inteiro, relido do servidor, com as permissões que o entity-location lê.
        async open(id) {
            this.saved = false;
            const [place] = await this.read(id).catch(() => []);

            if (place) {
                this.place = place;
                this.$refs.modal.open();
            } else {
                this.messages.error(this.text('falha'));
            }
        },

        // Lê o local pedindo a permissão, o que traz também o local em rascunho.
        read(id) {
            return new API('space').fetch('find', {
                id: `EQ(${id})`,
                status: 'GTE(0)',
                '@permissions': '@control',
                '@select': 'id,name,status,location,endereco,En_CEP,En_Nome_Logradouro,En_Num,En_Complemento,En_Bairro,En_Municipio,En_Estado,currentUserPermissions',
            }, { refresh: true });
        },

        // Fechou sem salvar: o local volta ao gravado, na agenda e na lista também.
        close() {
            if (!this.saved && this.place?.id) {
                this.read(this.place.id).catch(() => {});
            }
            this.place = null;
        },

        // O local novo de "Meus locais", de quem cadastra, com a permissão que o entity-location lê. O tipo vem do
        // servidor.
        create() {
            const place = new Entity('space');
            place.currentUserPermissions = { '@control': true };
            this.place = place;
            this.$refs.modal.open();
        },

        // Os festejos do local atualizam a UF e a cidade sozinhos (Plugin, entity(Space).save:finish).
        async save(modal) {
            this.saving = true;

            try {
                await this.place.save();
                this.saved = true;
                this.$emit('saved', this.place);
                modal.close();
            } catch (error) {
                // O core já avisou e marcou os campos.
            } finally {
                this.saving = false;
            }
        },
    },
});
