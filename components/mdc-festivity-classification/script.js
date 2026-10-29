app.component('mdc-festivity-classification', {
    template: $TEMPLATES['mdc-festivity-classification'],

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
        const text = Utils.getTexts('mdc-festivity-classification');
        return { text };
    },

    data() {
        const config = $MAPAS.config.mdcFestivity;

        return {
            classification: config.classification,
            keys: config.classificationKeys,
            // segmento => {typologies, other}: o que estava marcado em cada um, só na tela; grava-se só o segmento atual.
            kept: {},
        };
    },

    computed: {
        segment() {
            return this.entity[this.keys.segment] || '';
        },

        typologies() {
            return this.classification.find((item) => item.segmento === this.segment)?.tipologias || [];
        },

        selected() {
            return this.entity[this.keys.typologies] || [];
        },

        // Até duas, e o "Outro" escrito conta como uma (FestivityForm::invalid).
        isFull() {
            return this.selected.length + (this.entity[this.keys.other] ? 1 : 0) >= 2;
        },

        // As etiquetas de "Selecionados": o x desmarca (no segmento, desmarca tudo).
        tags() {
            if (!this.segment) {
                return [];
            }

            const other = this.entity[this.keys.other];

            return [
                { key: 'segmento', label: this.segment, segment: true, remove: () => this.changeSegment('') },
                ...this.selected.map((typology) => ({ key: typology, label: typology, remove: () => this.toggle(typology) })),
                ...(other ? [{ key: 'outro', label: this.format('outro etiqueta', other), remove: () => { this.entity[this.keys.other] = ''; } }] : []),
            ];
        },

        messages() {
            return [this.keys.segment, this.keys.typologies, this.keys.other].flatMap((key) => this.entity.__validationErrors?.[key] || []);
        },
    },

    methods: {
        format(key, value) {
            return this.text(key).replace('%s', value);
        },

        // Pelo rádio (clique ou setas): guarda o que estava marcado, devolve o do segmento escolhido e o foco volta ao
        // rádio.
        chooseSegment(segment, index) {
            if (this.segment) {
                this.kept[this.segment] = { typologies: this.selected, other: this.entity[this.keys.other] || '' };
            }

            const kept = this.kept[segment] || { typologies: [], other: '' };
            this.entity[this.keys.segment] = segment;
            this.entity[this.keys.typologies] = kept.typologies;
            this.entity[this.keys.other] = kept.other;
            this.$nextTick(() => this.$refs['segment-' + index]?.[0]?.focus());
        },

        // Pelo x da etiqueta: desmarca de vez, inclusive o guardado.
        changeSegment(segment) {
            delete this.kept[this.entity[this.keys.segment]];
            this.entity[this.keys.segment] = segment;
            this.entity[this.keys.typologies] = [];
            this.entity[this.keys.other] = '';
        },

        toggle(typology) {
            const selected = this.selected.includes(typology)
                ? this.selected.filter((item) => item !== typology)
                : [...this.selected, typology];

            this.entity[this.keys.typologies] = selected;
        },
    },
});
