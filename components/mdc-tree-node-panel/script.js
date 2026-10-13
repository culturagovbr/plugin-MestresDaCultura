app.component('mdc-tree-node-panel', {
    template: $TEMPLATES['mdc-tree-node-panel'],
    emits: ['select'],

    // As ações vão para a página (mdc-tree-curation), que chama a rota e relê a árvore.
    inject: ['curation'],

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

    data() {
        return {
            newName: '',
            childName: '',
        };
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

        // Atividade é o último nível; abaixo de desativado não se adiciona.
        childLevel() {
            const levels = ['segmento', 'tipologia', 'subtipologia', 'atividade'];

            return this.node.enabled && this.path.length < levels.length ? this.text(levels[this.path.length]).toLowerCase() : null;
        },

        parentDisabled() {
            return this.parent && this.parent.id !== 0 && !this.parent.enabled;
        },
    },

    methods: {
        // O modal só fecha quando a ação deu certo; recusada, fica aberto com a mensagem da regra.
        async run(modal, action) {
            modal.loading(true);
            const done = await action();
            modal.loading(false);

            if (done) {
                modal.close();
            }
        },
    },
});
