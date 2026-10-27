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
            // O "Outro" marcado com o texto ainda vazio.
            otherOpen: false,
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

        hasOther() {
            return this.otherOpen || !!this.entity[this.keys.other];
        },

        // Até duas, e o "Outro" conta como uma (FestivityForm::invalid).
        isFull() {
            return this.selected.length + (this.hasOther ? 1 : 0) >= 2;
        },
    },

    methods: {
        errors(key) {
            return this.entity.__validationErrors?.[key] || [];
        },

        changeSegment(segment) {
            this.entity[this.keys.segment] = segment;
            this.entity[this.keys.typologies] = [];
            this.entity[this.keys.other] = '';
            this.otherOpen = false;
        },

        toggle(typology) {
            const selected = this.selected.includes(typology)
                ? this.selected.filter((item) => item !== typology)
                : [...this.selected, typology];

            this.entity[this.keys.typologies] = selected;
        },

        toggleOther() {
            if (this.hasOther) {
                this.entity[this.keys.other] = '';
                this.otherOpen = false;
            } else {
                this.otherOpen = true;
            }
        },
    },
});
