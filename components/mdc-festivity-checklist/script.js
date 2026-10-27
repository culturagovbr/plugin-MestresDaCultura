app.component('mdc-festivity-checklist', {
    template: $TEMPLATES['mdc-festivity-checklist'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        // a lista (multiselect) e o campo do "Outro"
        prop: {
            type: String,
            required: true,
        },
        otherProp: {
            type: String,
            required: true,
        },
        // [{label, items: [{value, label}]}]
        groups: {
            type: Array,
            required: true,
        },
        title: {
            type: String,
            required: true,
        },
        description: {
            type: String,
            default: '',
        },
        // Um grupo aberto por vez, como a planilha pede na economia: o segmento e depois as ocupações.
        collapsible: {
            type: Boolean,
            default: false,
        },
        required: {
            type: Boolean,
            default: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-festivity-checklist');
        return { text };
    },

    data() {
        return {
            // O "Outro" marcado com o texto ainda vazio.
            otherOpen: false,
        };
    },

    computed: {
        checked() {
            return this.entity[this.prop] || [];
        },

        hasOther() {
            return this.otherOpen || !!this.entity[this.otherProp];
        },

        errors() {
            return this.entity.__validationErrors?.[this.prop] || [];
        },
    },

    methods: {
        countIn(group) {
            return group.items.filter((item) => this.checked.includes(item.value)).length;
        },

        toggle(value) {
            this.entity[this.prop] = this.checked.includes(value)
                ? this.checked.filter((item) => item !== value)
                : [...this.checked, value];
        },

        toggleOther() {
            if (this.hasOther) {
                this.entity[this.otherProp] = '';
                this.otherOpen = false;
            } else {
                this.otherOpen = true;
            }
        },
    },
});
