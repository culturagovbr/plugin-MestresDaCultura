app.component('mdc-festivity-form', {
    template: $TEMPLATES['mdc-festivity-form'],

    setup() {
        const text = Utils.getTexts('mdc-festivity-form');
        return { text };
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

    computed: {
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
                window.history.replaceState(null, '', `${$MAPAS.config.mdcFestivity.formUrl}${this.entity.id}/`);
            }
        },

        async check() {
            const url = `${$MAPAS.config.mdcFestivity.checkUrl}${this.entity.id}/?step=${this.step}`;
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

            return response.ok ? response.json() : {};
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

        show(step) {
            this.step = step;
            this.$nextTick(() => this.$refs.stepTitle?.focus());
        },

        async next() {
            this.saving = true;

            try {
                await this.save();
                this.errors = await this.check();
                this.entity.__validationErrors = { ...this.errors };

                if (!this.messages.length) {
                    this.show(this.step + 1);
                }
            } catch (error) {
                // O core já avisou e marcou os campos.
            } finally {
                this.saving = false;
            }
        },

        async goTo(step) {
            if (step === this.step || this.saving) {
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
                // O core já avisou e marcou os campos.
            } finally {
                this.saving = false;
            }
        },

        // Publicar cobra as 6 etapas (FestivityForm no servidor); o que faltar aparece aqui.
        async publish() {
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
