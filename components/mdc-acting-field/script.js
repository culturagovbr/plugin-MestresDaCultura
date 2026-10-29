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
        // O rótulo do `entity-field`, que nomeia o grupo.
        fieldLabel: {
            type: String,
            default: '',
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
        const nodes = value.nodes || [];

        return {
            tree: null,
            loading: true,
            failed: false,
            otherMaxLength: MDC_ACTING_OTHER_MAX_LENGTH,

            // Até o envio, a árvore atual manda; depois dele, a resposta é o espelho gravado.
            sent: Number(this.entity.status || 0) !== 0,

            // id => nó, e id => id do pai, da árvore atual; montados quando ela chega
            nodeOf: {},
            parentOf: {},

            // id => entrada do espelho gravado ({id, parent, name})
            stored: Object.fromEntries(nodes.map(node => [node.id, node])),
            checked: new Set(nodes.map(node => node.id)),
            // id do pai (ou 'null', para o geral) => texto
            others: Object.fromEntries((value.others || []).map(o => [String(o.parent), o.text])),

            // nomes que saíram da lista, desmarcados ao abrir o rascunho
            removed: [],
        };
    },

    computed: {
        // Uma etiqueta por nó marcado, na ordem da árvore; depois uma por "Outro".
        tags() {
            const tags = this.orderedChecked().map(id => ({
                key: 'n' + id,
                label: this.nameOf(id),
                segment: this.parentFor(id) === null,
                remove: () => this.uncheck(id),
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
            this.tree = tree;

            if (this.sent) {
                return;
            }

            const dropped = this.dropRemovedFromTree();
            const completed = this.addMovedAncestors();

            if (dropped || completed) {
                this.emit();
            }
        },

        // Rascunho segue a lista atual: o que saiu dela (com o que estava abaixo) é desmarcado, com aviso.
        dropRemovedFromTree() {
            const gone = [...this.checked].filter(id => !(id in this.nodeOf));
            const goneOthers = Object.keys(this.others).filter(parent => parent !== 'null' && !(parent in this.nodeOf));

            this.removed = [
                ...gone.map(id => this.stored[id].name),
                ...goneOthers.map(parent => this.format('outro etiqueta', this.others[parent])),
            ];

            gone.forEach(id => this.checked.delete(id));
            goneOthers.forEach(parent => delete this.others[parent]);

            return this.removed.length > 0;
        },

        // Item que a curadoria mudou de lugar continua marcado e leva junto o caminho novo.
        addMovedAncestors() {
            const missing = [...this.checked].flatMap(id => this.ancestorsOf(id)).filter(id => !this.checked.has(id));

            missing.forEach(id => this.checked.add(id));

            return missing.length > 0;
        },

        isChecked(node) {
            return this.checked.has(node.id);
        },

        // Marcar traz os pais junto; desmarcar leva o que está abaixo e os "Outro" de dentro. O foco volta ao segmento.
        toggleSegment(segment, on) {
            this.toggle(segment, on);
            this.$nextTick(() => this.$refs['segment-' + segment.id]?.[0]?.focus());
        },

        toggle(node, on) {
            if (!on) {
                this.uncheck(node.id);
                return;
            }

            if (this.disabled) {
                return;
            }

            for (let id = node.id; id !== null; id = this.parentFor(id)) {
                this.checked.add(id);
            }

            this.emit();
        },

        uncheck(id) {
            if (this.disabled) {
                return;
            }

            const drop = [id, ...[...this.checked].filter(other => this.ancestorsOf(other).includes(id))];

            drop.forEach(dropped => {
                this.checked.delete(dropped);
                delete this.others[String(dropped)];
            });

            this.emit();
        },

        // Rótulo da caixa que abre debaixo de um nó marcado: "Subtipologias de Artesanato:".
        childrenHeading(node) {
            return this.format('filhos nivel ' + (this.ancestorsOf(node.id).length + 1), node.name);
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

        // Pai e nome vêm da árvore atual; depois do envio, ou fora dela, do espelho gravado.
        parentFor(id) {
            if ((this.sent && this.stored[id]) || !(id in this.parentOf)) {
                return this.stored[id]?.parent ?? null;
            }

            return this.parentOf[id];
        },

        nameOf(id) {
            if ((this.sent && this.stored[id]) || !(id in this.nodeOf)) {
                return this.stored[id]?.name;
            }

            return this.nodeOf[id].name;
        },

        ancestorsOf(id) {
            const ancestors = [];

            for (let parent = this.parentFor(id); parent !== null; parent = this.parentFor(parent)) {
                ancestors.push(parent);
            }

            return ancestors;
        },

        // Os marcados na ordem da árvore; depois do envio, o que a árvore atual não alcança vem em seguida, na ordem
        // em que foi gravado.
        orderedChecked() {
            const ordered = [];
            const visit = nodes => nodes.forEach(node => {
                if (this.checked.has(node.id)) {
                    ordered.push(node.id);
                    visit(node.children);
                }
            });

            visit(this.tree || []);

            return [...ordered, ...[...this.checked].filter(id => !ordered.includes(id))];
        },

        emit() {
            const nodes = this.orderedChecked().map(id => ({ id, parent: this.parentFor(id), name: this.nameOf(id) }));

            const others = Object.entries(this.others)
                .filter(([, text]) => text)
                .map(([parent, text]) => ({ parent: parent === 'null' ? null : Number(parent), text }));

            this.$emit('change', nodes.length || others.length ? { nodes, others } : null);
        },
    },
});
