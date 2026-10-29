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
        // Os grupos abertos, para o aria-expanded do título: nascem abertos os que já têm item marcado.
        const checked = this.entity[this.prop] || [];
        return {
            opened: new Set(this.groups.filter((group) => group.label && group.items.some((item) => checked.includes(item.value))).map((group) => group.label)),
        };
    },

    computed: {
        checked() {
            return this.entity[this.prop] || [];
        },

        otherId() {
            return `mdc-festivity-other-${this.prop}`;
        },

        // As etiquetas de "Selecionados": o x desmarca. O que saiu da lista aparece pelo valor.
        tags() {
            const labels = Object.fromEntries(this.groups.flatMap((group) => group.items.map((item) => [item.value, item.label])));
            const other = this.entity[this.otherProp];

            return [
                ...this.checked.map((value) => ({ key: value, label: labels[value] ?? value, remove: () => this.toggle(value) })),
                ...(other ? [{ key: 'outro', label: this.text('outro etiqueta').replace('%s', other), remove: () => { this.entity[this.otherProp] = ''; } }] : []),
            ];
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
    },
});
