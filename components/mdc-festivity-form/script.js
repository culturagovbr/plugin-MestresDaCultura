app.component('mdc-festivity-form', {
    template: $TEMPLATES['mdc-festivity-form'],

    setup() {
        const text = Utils.getTexts('mdc-festivity-form');
        // O aviso do core; `messages` é o que a etapa cobra.
        const notify = useMessages();
        return { text, notify };
    },

    data() {
        return {
            entity: this.createEntity(),
            steps: $MAPAS.config.mdcFestivity.steps,
            lists: $MAPAS.config.mdcFestivity.lists,
            producers: $MAPAS.config.mdcFestivity.producers,
            funding: $MAPAS.config.mdcFestivity.funding,
            step: 0,
            saving: false,
            // o que a etapa (ou, ao publicar, o festejo) ainda cobra: campo => mensagens
            errors: {},
        };
    },

    // Apaga o que depende de uma resposta quando ela muda, como FestivityFields::clearUnanswered na gravação.
    created() {
        const dependencies = Object.entries($MAPAS.config.mdcFestivity.dependencies || {});

        for (const parent of new Set(dependencies.map(([, [question]]) => question))) {
            const clear = () => {
                for (const [key, [question, answers]] of dependencies) {
                    const value = this.entity[key];

                    if (question === parent && !answers.includes(this.entity[parent]) && value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length)) {
                        this.entity[key] = Array.isArray(value) ? [] : '';
                    }
                }
            };

            // Limpa depois da tela atualizada e de novo na volta seguinte, depois do setTimeout do entity-field.
            this.$watch(() => this.entity[parent], () => {
                clear();
                setTimeout(clear);
            }, { flush: 'post' });
        }
    },

    computed: {
        // As redes sociais na ordem e com os nomes do cadastro da mestra.
        socialNetworks() {
            return {
                instagram: 'Instagram',
                twitter: 'X (antigo Twitter)',
                facebook: 'Facebook',
                vimeo: 'Vimeo',
                youtube: 'YouTube',
                linkedin: 'LinkedIn',
                spotify: 'Spotify',
                pinterest: 'Pinterest',
                tiktok: 'TikTok',
                fediverso: 'Fediverso (Mastodon e outros)',
            };
        },

        labels() {
            return this.steps.map((step) => step.label);
        },

        isLast() {
            return this.step === this.steps.length - 1;
        },

        // Antes do primeiro rascunho, só a primeira etapa: as outras gravam no evento.
        disabledSteps() {
            return this.entity.id ? [] : this.steps.map((step, index) => index).slice(1);
        },

        messages() {
            return Object.values(this.errors).flat();
        },

        currentYear() {
            return new Date().getFullYear();
        },
    },

    methods: {
        // O campo é obrigatório quando a etapa o cobra no servidor (FestivityForm).
        isRequired(prop) {
            return ($MAPAS.config.mdcFestivity.required[this.step] || []).includes(prop);
        },

        createEntity() {
            const requested = $MAPAS.requestedEntity;

            if (requested) {
                const entity = new API('event').getEntityInstance(requested.id);
                entity.populate(requested);
                return entity;
            }

            // O evento novo, em rascunho.
            const entity = new Entity('event');
            entity.type = 1;
            entity.status = 0;
            entity.terms = { linguagem: [] };
            return entity;
        },

        // Salva o rascunho; no primeiro, o endereço passa a ser o de continuar. Nas etapas 4 e 5, antes, o perfil de
        // quem cadastra (mdc-festivity-person).
        async save() {
            const isNew = !this.entity.id;
            await this.$refs.person?.save();
            await this.entity.save();

            if (isNew && this.entity.id) {
                window.history.replaceState(null, '', `${$MAPAS.config.mdcFestivity.editUrl}${this.entity.id}/`);
            }
        },

        // `step` null confere as 6 etapas.
        async check(step = this.step) {
            const query = step === null ? '' : `?step=${step}`;
            const url = `${$MAPAS.config.mdcFestivity.checkUrl}${this.entity.id}/${query}`;
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

            if (!response.ok) {
                throw new Error(response.status);
            }

            return response.json();
        },

        // O formulário só marca; a data do aceite é a do servidor (FestivityFields::stampConsent).
        setConsent(checked) {
            this.entity.mdcFestejoAceite = checked ? '1' : '';

            if (checked) {
                delete this.errors.mdcFestejoAceite;
                if (this.entity.__validationErrors) {
                    delete this.entity.__validationErrors.mdcFestejoAceite;
                }
            }
        },

        // O erro da gravação, inclusive de campos de outras etapas, entra na lista "Para seguir, falta".
        failed(error) {
            if (error?.data && typeof error.data === 'object') {
                this.errors = error.data;
            }
        },

        // Um período preenchido na agenda e não adicionado barra a troca de etapa e a saída, e leva a pessoa até ele.
        agendaPending() {
            if (!this.$refs.agenda?.pending()) {
                return false;
            }

            this.errors = { pendingPeriod: [this.text('periodo pendente')] };
            this.$refs.agenda.focusPending();
            return true;
        },

        // O que a agenda cobrava sai quando ela muda; a próxima conferência diz se ainda falta.
        agendaChanged() {
            for (const key of ['occurrences', 'place', 'removedPlace']) {
                delete this.errors[key];
                delete this.entity.__validationErrors?.[key];
            }
        },

        // Confere e marca o que falta; true quando nada falta.
        async confirm(step) {
            try {
                this.errors = await this.check(step);
                this.entity.__validationErrors = { ...this.errors };
                return !this.messages.length;
            } catch (error) {
                this.notify.error(this.text('erro'));
                return false;
            }
        },

        show(step) {
            this.step = step;
            this.$nextTick(() => this.$refs.stepTitle?.focus());
        },

        async next() {
            if (this.agendaPending()) {
                return;
            }

            this.saving = true;

            try {
                await this.save();
            } catch (error) {
                this.failed(error);
                this.saving = false;
                return;
            }

            if (await this.confirm(this.step)) {
                this.show(this.step + 1);
            }

            this.saving = false;
        },

        async goTo(step) {
            if (step === this.step || this.saving || this.agendaPending()) {
                return;
            }

            this.saving = true;

            try {
                if (this.entity.id) {
                    await this.save();
                }
                this.errors = {};
                this.show(step);
            } catch (error) {
                this.failed(error);
            } finally {
                this.saving = false;
            }
        },

        // Grava a etapa sem trocar de etapa.
        async saveStep() {
            if (this.agendaPending()) {
                return;
            }

            this.saving = true;

            try {
                await this.save();
                this.errors = {};
            } catch (error) {
                this.failed(error);
            } finally {
                this.saving = false;
            }
        },

        async saveAndExit(modal) {
            if (this.agendaPending()) {
                return;
            }

            this.saving = true;

            try {
                await this.save();
            } catch (error) {
                this.failed(error);
                this.saving = false;
                return;
            }

            this.errors = {};

            // O festejo publicado só sai com as 6 etapas; o rascunho sai sem cobrar.
            if (this.entity.status <= 0 || await this.confirm(null)) {
                modal.open();
            }

            this.saving = false;
        },

        // Publicar cobra as 6 etapas (FestivityForm no servidor); o que faltar aparece aqui.
        async publish() {
            if (this.agendaPending()) {
                return;
            }

            this.saving = true;

            try {
                await this.save();
                await this.entity.publish();
                window.location.href = this.entity.singleUrl;
            } catch (error) {
                this.errors = error?.data && typeof error.data === 'object' ? error.data : {};
            } finally {
                this.saving = false;
            }
        },
    },
});
