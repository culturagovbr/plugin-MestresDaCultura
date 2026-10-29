app.component('mdc-festivity-agenda', {
    template: $TEMPLATES['mdc-festivity-agenda'],

    emits: ['change'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        required: {
            type: Boolean,
            default: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-agenda');
        return { text };
    },

    data() {
        return {
            occurrences: [],
            // os locais de quem cadastra, para reaproveitar
            places: [],
            adding: false,
            placeId: '',
            newPlace: null,
            startsOn: '',
            until: '',
            startsAt: '',
            endsAt: '',
            error: '',
            saving: false,
            loading: true,
            failed: false,
        };
    },

    computed: {
        // O que o servidor cobra da agenda (FestivityForm): sem período, ou o local sem estado e município.
        errors() {
            const errors = this.entity.__validationErrors || {};
            return [...(errors.occurrences || []), ...(errors.place || []), ...(errors.removedPlace || [])];
        },
    },

    // Lê a agenda e os locais; se a leitura falhar, mostra o aviso.
    async created() {
        try {
            await this.refresh();
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },

    methods: {
        async load() {
            const api = new API('eventoccurrence');
            this.occurrences = await api.find({
                event: `EQ(${this.entity.id})`,
                '@select': 'id,rule,space.{name,status,En_Municipio,En_Estado,currentUserPermissions}',
                '@order': 'startsOn ASC',
            });
        },

        // Local na lixeira, ou que não vem para quem vê.
        isRemoved(occurrence) {
            return !occurrence.space || occurrence.space.status < 0;
        },

        // Só quem pode editar o local.
        canEditPlace(occurrence) {
            return !this.isRemoved(occurrence) && !!occurrence.space.currentUserPermissions?.modify;
        },

        // O local editado (mdc-place-editor) muda o nome na agenda e na lista de escolha.
        async refresh() {
            await Promise.all([this.load(), this.loadPlaces()]);
        },

        async loadPlaces() {
            const api = new API('space');
            this.places = await api.find({
                '@select': 'id,name,En_Municipio,En_Estado',
                '@permissions': '@control',
                // o local é rascunho até um festejo publicado usá-lo (MDC\Services\FestivityPlace::syncStatus)
                'status': 'GTE(0)',
                '@order': 'name ASC',
            });
        },

        placeOf(space) {
            return [space?.En_Municipio, space?.En_Estado].filter(Boolean).join('/') || this.text('sem endereco');
        },

        start() {
            this.adding = true;
            this.placeId = this.places.length === 1 ? this.places[0].id : '';
            this.choosePlace();
        },

        // O local novo é de quem cadastra, com a permissão que o entity-location lê.
        choosePlace() {
            this.newPlace = null;

            if (this.placeId === 'new') {
                const place = new Entity('space');
                place.currentUserPermissions = { '@control': true };
                this.newPlace = place;
            }
        },

        cancel() {
            this.adding = false;
            this.error = '';
            this.placeId = '';
            this.newPlace = null;
            this.startsOn = this.until = this.startsAt = this.endsAt = '';
        },

        // O texto do período, sem repetir o mês e o ano iguais: "3 a 5 de dezembro de 2026".
        describe() {
            const start = new Date(`${this.startsOn}T12:00:00`);
            const end = this.until && this.until !== this.startsOn ? new Date(`${this.until}T12:00:00`) : null;
            const full = (date) => date.toLocaleDateString('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' });
            let first = full(start);

            if (end && end.getFullYear() === start.getFullYear()) {
                first = end.getMonth() === start.getMonth()
                    ? String(start.getDate())
                    : start.toLocaleDateString('pt-BR', { day: 'numeric', month: 'long' });
            }

            const days = end ? this.text('de a').replace('{inicio}', first).replace('{fim}', full(end)) : first;
            const hours = this.endsAt
                ? this.text('das as').replace('{inicio}', this.startsAt).replace('{fim}', this.endsAt)
                : this.text('a partir das').replace('{inicio}', this.startsAt);

            return `${days}, ${hours}`;
        },

        // A duração em minutos; o término antes do início vai até a madrugada.
        minutes() {
            const [startHour, startMinute] = this.startsAt.split(':').map(Number);
            const [endHour, endMinute] = this.endsAt.split(':').map(Number);
            const minutes = (endHour * 60 + endMinute) - (startHour * 60 + startMinute);

            return minutes > 0 ? minutes : minutes + 24 * 60;
        },

        problem() {
            if (!this.startsOn || !this.startsAt) {
                return this.text('falta data');
            }
            if (this.until && this.until < this.startsOn) {
                return this.text('fim antes');
            }
            if (!this.placeId) {
                return this.text('falta local');
            }
            if (this.placeId === 'new' && (!this.newPlace.name || !this.newPlace.En_Estado || !this.newPlace.En_Municipio)) {
                return this.text('falta endereco');
            }

            return '';
        },

        async add() {
            this.error = this.problem();

            if (this.error) {
                return;
            }

            this.saving = true;

            try {
                if (this.placeId === 'new') {
                    await this.newPlace.save();
                }

                // Um dia, ou todos os dias do início ao fim; a regra vai inteira, com a duração.
                const range = this.until && this.until !== this.startsOn;
                const rule = {
                    eventId: this.entity.id,
                    spaceId: this.placeId === 'new' ? this.newPlace.id : this.placeId,
                    frequency: range ? 'daily' : 'once',
                    startsOn: this.startsOn,
                    startsAt: this.startsAt,
                    description: this.describe(),
                    price: '',
                };
                if (range) {
                    rule.until = this.until;
                }
                if (this.endsAt) {
                    rule.endsAt = this.endsAt;
                    rule.duration = this.minutes();
                }

                const response = await new API('eventoccurrence').POST(Utils.createUrl('eventoccurrence', 'index'), rule);
                if (!response.ok) {
                    throw await response.json().catch(() => ({}));
                }

                this.cancel();
                await Promise.all([this.load(), this.loadPlaces()]);
                this.$emit('change');
            } catch (error) {
                this.error = error?.data && typeof error.data === 'object' ? Object.values(error.data).flat().join(' ') : this.text('falha');
            } finally {
                this.saving = false;
            }
        },

        async remove(occurrence) {
            try {
                await occurrence.delete(true);
                await this.load();
                this.$emit('change');
            } catch (error) {
                // O core já avisou.
            }
        },
    },
});
