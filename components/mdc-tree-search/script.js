// Quantos resultados a lista mostra; o resto pede uma busca mais precisa.
const MDC_TREE_SEARCH_LIMIT = 12;

app.component('mdc-tree-search', {
    template: $TEMPLATES['mdc-tree-search'],
    emits: ['select', 'matches'],

    props: {
        // Segmentos da árvore da curadoria: {id, name, enabled, children}
        tree: {
            type: Array,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('mdc-tree-search');
        const uid = Vue.useId();

        const format = (key, ...values) => MdcTreeText.format(text(key), ...values);

        return { text, format, uid };
    },

    data() {
        return {
            query: '',
            open: false,
            limit: MDC_TREE_SEARCH_LIMIT,
        };
    },

    computed: {
        // Todos os itens com o caminho até eles, para buscar sem caixa e sem acento.
        items() {
            const items = [];
            const visit = (nodes, path) => nodes.forEach(node => {
                items.push({ id: node.id, name: node.name, enabled: node.enabled, place: path.join(' › '), key: MdcTreeText.comparable(node.name) });
                visit(node.children, [...path, node.name]);
            });

            visit(this.tree, []);

            return items;
        },

        found() {
            const query = MdcTreeText.comparable(this.query);

            return query.length < 2 ? [] : this.items.filter(item => item.key.includes(query));
        },
    },

    watch: {
        found(found) {
            this.$emit('matches', found.map(item => item.id));
        },
    },

    methods: {

        choose(item) {
            this.open = false;
            this.$emit('select', item.id);
        },

        // Fecha quando o foco sai do campo e da lista.
        leave(event) {
            if (!this.$el.contains(event.relatedTarget)) {
                this.open = false;
            }
        },
    },
});
