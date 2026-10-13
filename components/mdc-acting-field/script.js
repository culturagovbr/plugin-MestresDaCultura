// Mesmo limite de ActingField::OTHER_MAX_LENGTH, no servidor.
const MDC_ACTING_OTHER_MAX_LENGTH = 255;

app.component('mdc-acting-field', {
    template: $TEMPLATES['mdc-acting-field'],
    emits: ['change'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        prop: {
            type: String,
            required: true,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-acting-field');
        const uid = Vue.useId();

        // substitui cada `%s` do texto pelo próximo argumento
        const format = (key, ...values) => values.reduce((s, v) => s.replace('%s', v), text(key));

        return { text, format, uid };
    },

    // A lista de cada nível lê e altera a seleção por aqui, sem repassar props nível a nível.
    provide() {
        return { acting: this };
    },

    data() {
        const value = this.entity[this.prop] || {};

        return {
            tree: null,
            loading: true,
            failed: false,
            removed: false,
            otherMaxLength: MDC_ACTING_OTHER_MAX_LENGTH,

            // id => nó, e id => id do pai; montados quando a árvore chega
            nodeOf: {},
            parentOf: {},

            checked: new Set((value.nodes || []).map(Number)),
            // id do pai (ou 'null', para o geral) => texto
            others: Object.fromEntries((value.others || []).map(o => [String(o.parent), o.text])),
        };
    },

    computed: {
        // Uma etiqueta por nó marcado, na ordem da árvore; depois uma por "Outro".
        tags() {
            const tags = this.orderedChecked().map(id => ({
                key: 'n' + id,
                label: this.nodeOf[id].name,
                segment: this.parentOf[id] === null,
                remove: () => this.toggle(this.nodeOf[id], false),
            }));

            for (const [parent, value] of Object.entries(this.others)) {
                tags.push({
                    key: 'o' + parent,
                    label: this.format('outro etiqueta', value),
                    segment: false,
                    remove: () => this.setOther(parent === 'null' ? null : Number(parent), ''),
                });
            }

            return tags;
        },
    },

    async created() {
        try {
            const response = await fetch(Utils.createUrl('mdc', 'tree'), { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error(response.status);
            }

            this.indexTree(await response.json());
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },

    methods: {
        indexTree(tree) {
            const visit = (nodes, parent) => {
                for (const node of nodes) {
                    this.nodeOf[node.id] = node;
                    this.parentOf[node.id] = parent;
                    visit(node.children, node.id);
                }
            };

            visit(tree, null);

            // Nó desativado pela curadoria não aparece na lista, e ninguém conseguiria desmarcá-lo.
            const gone = [...this.checked].filter(id => !(id in this.nodeOf));
            const goneOthers = Object.keys(this.others).filter(parent => parent !== 'null' && !(parent in this.nodeOf));

            if (gone.length || goneOthers.length) {
                gone.forEach(id => this.checked.delete(id));
                goneOthers.forEach(parent => delete this.others[parent]);
                this.removed = true;
            }

            this.tree = tree;

            if (this.removed) {
                this.emit();
            }
        },

        isChecked(node) {
            return this.checked.has(node.id);
        },

        // Marcar traz os pais junto; desmarcar leva os filhos e os "Outro" de dentro deles.
        toggle(node, on) {
            if (this.disabled) {
                return;
            }

            if (on) {
                for (let id = node.id; id !== null; id = this.parentOf[id]) {
                    this.checked.add(id);
                }
            } else {
                const drop = [node.id, ...this.descendants(node)];

                drop.forEach(id => {
                    this.checked.delete(id);
                    delete this.others[String(id)];
                });
            }

            this.emit();
        },

        // Rótulo da caixa que abre debaixo de um nó marcado: "Subtipologias de Artesanato".
        childrenHeading(node) {
            return this.format('filhos nivel ' + this.path(node.id).length, node.name);
        },

        otherOf(parent) {
            return this.others[String(parent)] || '';
        },

        setOther(parent, value) {
            if (this.disabled) {
                return;
            }

            value = value.trim().slice(0, this.otherMaxLength);

            if (value) {
                this.others[String(parent)] = value;
            } else {
                delete this.others[String(parent)];
            }

            this.emit();
        },

        descendants(node) {
            return node.children.flatMap(child => [child.id, ...this.descendants(child)]);
        },

        path(id) {
            const names = [];

            for (; id !== null; id = this.parentOf[id]) {
                names.unshift(this.nodeOf[id].name);
            }

            return names;
        },

        // Na ordem da árvore, para o valor gravado não mudar só porque a ordem dos cliques mudou.
        orderedChecked() {
            const ordered = [];
            const visit = nodes => nodes.forEach(node => {
                if (this.checked.has(node.id)) {
                    ordered.push(node.id);
                    visit(node.children);
                }
            });

            visit(this.tree || []);

            return ordered;
        },

        emit() {
            const others = Object.entries(this.others)
                .filter(([, text]) => text)
                .map(([parent, text]) => ({ parent: parent === 'null' ? null : Number(parent), text }));

            const nodes = this.orderedChecked();

            this.$emit('change', nodes.length || others.length ? { nodes, others } : null);
        },
    },
});
