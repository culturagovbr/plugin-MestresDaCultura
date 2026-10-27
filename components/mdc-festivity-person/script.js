app.component('mdc-festivity-person', {
    template: $TEMPLATES['mdc-festivity-person'],

    props: {
        // "contact": a pessoa responsável (etapa 4); "markers": os marcadores sociais (etapa 5)
        part: {
            type: String,
            default: 'contact',
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-person');
        return { text };
    },

    data() {
        // A pessoa vem do servidor (FestivityForm::person), com os dados só para quem pode editar o perfil dela.
        const person = $MAPAS.config.mdcFestivity.person;
        let agent = null;

        if (person?.data) {
            agent = new API('agent').getEntityInstance(person.id);
            agent.populate({ id: person.id, ...person.data });
        }

        return {
            agent,
            // Se quem edita o festejo pode editar o perfil do dono.
            canEdit: !!person?.canEdit,
            // A autorização de tornar público é da própria pessoa, em primeira pessoa.
            isOwn: !!person?.isOwn,
            publicEmail: !!agent?.emailPublico,
            publicPhone: !!agent?.telefonePublico,
        };
    },

    methods: {
        // O e-mail e o telefone públicos só com a autorização; sem ela, apagados.
        setPublic(kind, allowed) {
            const [flag, publicProp, privateProp] = kind === 'email'
                ? ['publicEmail', 'emailPublico', 'emailPrivado']
                : ['publicPhone', 'telefonePublico', 'telefone1'];

            this[flag] = allowed;
            this.agent[publicProp] = allowed ? (this.agent[publicProp] || this.agent[privateProp] || '') : '';
        },

        // O formulário chama antes de salvar o festejo: os dados da pessoa ficam no perfil dela.
        async save() {
            if (this.canEdit && Object.keys(this.agent.data(true)).length) {
                await this.agent.save();
            }
        },
    },
});
