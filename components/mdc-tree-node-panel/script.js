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
            moveQuery: '',
            moveTarget: null,
            moveLimit: 30,
            equivalenceQuery: '',
            equivalenceTarget: null,
            // histórico do item, buscado ao abrir o bloco
            history: null,
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

        /**
         * Destinos de "Mover", com as regras do serviço (TreeCuration::move): ativos, fora do próprio ramo,
         * não o lugar atual, e onde o ramo inteiro (desativados inclusive) cabe nos 4 níveis.
         */
        moveTargets() {
            if (this.isRoot || !this.node.enabled) {
                return [];
            }

            const height = node => node.children.reduce((deepest, child) => Math.max(deepest, 1 + height(child)), 0);
            const inBranch = new Set();
            const mark = node => {
                inBranch.add(node.id);
                node.children.forEach(mark);
            };
            mark(this.node);

            const need = height(this.node);

            return Object.values(this.curation.index)
                .filter(({ node, path }) => node.enabled && !inBranch.has(node.id) && node.id !== this.parent.id && path.length + 1 + need <= 4)
                .map(({ node, path }) => ({ id: node.id, place: node.id ? path.join(' › ') : this.text('virar segmento'), key: this.comparable(path.join(' ')) }))
                .sort((a, b) => a.place.localeCompare(b.place, 'pt-BR'));
        },

        moveFound() {
            const query = this.comparable(this.moveQuery);

            return query ? this.moveTargets.filter(target => target.key.includes(query)) : this.moveTargets;
        },

        // Referência de que o item é equivalente, com o caminho.
        reference() {
            return this.node.equivalentTo ? this.curation.index[this.node.equivalentTo] || null : null;
        },

        // Possíveis duplicatas, com o caminho de cada uma.
        duplicates() {
            return (this.node.duplicates || []).map(id => this.curation.index[id]).filter(Boolean);
        },

        // Itens que equivalem a este (ele é a referência deles).
        equivalents() {
            return Object.values(this.curation.index).filter(({ node }) => node.equivalentTo === this.node.id);
        },

        /**
         * Referências possíveis, com as regras de TreeEquivalence::mark: outro item, que não equivale a
         * nenhum (sem cadeias). Vazio se o item já equivale a algum ou se é referência de outros.
         */
        equivalenceTargets() {
            if (this.isRoot || this.reference || this.equivalents.length) {
                return [];
            }

            return Object.values(this.curation.index)
                .filter(({ node }) => node.id && node.id !== this.node.id && !node.equivalentTo)
                .map(({ node, path }) => ({ id: node.id, place: path.join(' › '), key: this.comparable(path.join(' ')) }))
                .sort((a, b) => a.place.localeCompare(b.place, 'pt-BR'));
        },

        equivalenceFound() {
            const query = this.comparable(this.equivalenceQuery);

            return query ? this.equivalenceTargets.filter(target => target.key.includes(query)) : this.equivalenceTargets;
        },

        parentDisabled() {
            return this.parent && this.parent.id !== 0 && !this.parent.enabled;
        },
    },

    watch: {
        'node.id'() {
            this.history = null;
        },
    },

    methods: {
        async loadHistory(event) {
            if (event.target.open && this.history === null) {
                this.history = await this.curation.history(this.node.id);
            }
        },

        when(timestamp) {
            return new Date(timestamp.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
        },

        comparable(text) {
            return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        },

        startMove() {
            this.moveQuery = '';
            this.moveTarget = null;
        },

        // Começa pela busca do mesmo nome.
        startEquivalence() {
            this.equivalenceQuery = this.node.name;
            this.equivalenceTarget = null;
        },

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
