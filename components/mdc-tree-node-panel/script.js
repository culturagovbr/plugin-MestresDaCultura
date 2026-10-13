app.component('mdc-tree-node-panel', {
    template: $TEMPLATES['mdc-tree-node-panel'],
    emits: ['select', 'reorder'],

    props: {
        // Item da árvore da curadoria: {id, name, enabled, sent, drafts, children}; id 0 é a raiz
        node: {
            type: Object,
            required: true,
        },
        // Pai do item (a raiz para os segmentos); null na raiz
        parent: {
            type: Object,
            default: null,
        },
        // Nomes da raiz até o item, inclusive
        path: {
            type: Array,
            required: true,
        },
        // Ação em andamento: os botões esperam
        busy: {
            type: Boolean,
            default: false,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-tree-node-panel');

        // substitui cada `%s` do texto pelo próximo argumento
        const format = (key, ...values) => values.reduce((s, v) => s.replace('%s', v), text(key));

        return { text, format };
    },

    computed: {
        isRoot() {
            return this.node.id === 0;
        },

        level() {
            return this.text(['raiz', 'segmento', 'tipologia', 'subtipologia', 'atividade'][this.path.length]);
        },

        branch() {
            const count = node => node.children.reduce((total, child) => total + 1 + count(child), 0);
            const active = node => node.children.reduce((total, child) => total + (child.enabled ? 1 : 0) + active(child), 0);

            return { all: count(this.node), active: active(this.node) };
        },

        // A ordem muda entre os irmãos ativos, os que aparecem no formulário.
        siblings() {
            return this.parent ? this.parent.children.filter(child => child.enabled) : [];
        },

        position() {
            return this.siblings.indexOf(this.node);
        },
    },
});
