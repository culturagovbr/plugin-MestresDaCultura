app.component('mdc-network-map', {
    template: $TEMPLATES['mdc-network-map'],

    setup() {
        const text = Utils.getTexts('mdc-network-map');

        return { text };
    },

    data() {
        // O plugin MDC põe na home o desenho dos estados (IBGE) e quantos há em cada um (MasterShowcase::byState).
        const showcase = $MAPAS.config.mdcShowcase ?? {};

        return {
            map: showcase.map ?? null,
            states: showcase.states ?? {},
            searchUrl: showcase.searchUrl ?? '',
            // A lista mostra os estados com mais gente; o resto abre em "Ver todos os estados".
            top: 10,
            showAll: false,
            names: {
                AC: 'Acre', AL: 'Alagoas', AM: 'Amazonas', AP: 'Amapá', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal',
                ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MG: 'Minas Gerais', MS: 'Mato Grosso do Sul',
                MT: 'Mato Grosso', PA: 'Pará', PB: 'Paraíba', PE: 'Pernambuco', PI: 'Piauí', PR: 'Paraná',
                RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RO: 'Rondônia', RR: 'Roraima', RS: 'Rio Grande do Sul',
                SC: 'Santa Catarina', SE: 'Sergipe', SP: 'São Paulo', TO: 'Tocantins',
            },
        };
    },

    computed: {
        max() {
            return Math.max(0, ...Object.values(this.states));
        },

        // Os estados com alguém, os com mais gente primeiro; no empate, pelo nome.
        listed() {
            return Object.keys(this.states)
                .filter((uf) => this.count(uf))
                .sort((a, b) => this.count(b) - this.count(a) || this.names[a].localeCompare(this.names[b]));
        },

        visible() {
            return this.showAll ? this.listed : this.listed.slice(0, this.top);
        },

        // Aberta, a lista vira duas colunas ao lado do mapa: a primeira com os dez com mais gente, a segunda com o resto.
        rows() {
            return Math.max(this.top, Math.ceil(this.listed.length / 2));
        },
    },

    methods: {
        count(uf) {
            return this.states[uf] ?? 0;
        },

        // Quatro faixas em relação ao estado com mais gente; sem ninguém, a faixa 0.
        level(uf) {
            return this.max ? Math.max(1, Math.ceil((this.count(uf) / this.max) * 4)) : 0;
        },

        label(uf) {
            const count = this.count(uf);
            const people = count === 1 ? this.text('mestra ou mestre') : this.text('mestras e mestres');

            return `${this.names[uf]}: ${count} ${people}`;
        },

        url(uf) {
            return `${this.searchUrl}?uf=${uf}`;
        },
    },
});
