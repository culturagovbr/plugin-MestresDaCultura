app.component('mdc-masters-search', {
    template: $TEMPLATES['mdc-masters-search'],

    props: {
        // a consulta do `search` do core: daqui sai a busca por nome (@keyword)
        pseudoQuery: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            uf: '',
            city: '',
            segment: '',
            typology: '',
            tree: [],
            ufs: ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'],
            select: 'id,name,singleUrl,shortDescription,longDescription,files.avatar,mdcNomeArtistico,mdcAtividades,mdcCidade',
        };
    },

    async created() {
        // A UF pode vir no endereço ("?uf=PE").
        const uf = new URLSearchParams(window.location.search).get('uf');
        if (this.ufs.includes(uf)) {
            this.uf = uf;
        }

        // A árvore pública, a mesma do formulário (mdc-acting-field).
        try {
            const response = await fetch(Utils.createUrl('mdc', 'tree'), { headers: { Accept: 'application/json' } });
            this.tree = response.ok ? await response.json() : [];
        } catch {
            this.tree = [];
        }
    },

    computed: {
        segments() {
            return this.tree;
        },

        typologies() {
            return this.tree.find((node) => node.id === this.segment)?.children ?? [];
        },

        // Só quem tem o selo do programa (MDC\Services\MasterPublicData grava UF, cidade e atuação na aprovação).
        query() {
            const query = Utils.parsePseudoQuery({ '@keyword': this.pseudoQuery['@keyword'] || undefined });
            const acting = this.typology || this.segment;

            query['@seals'] = String($MAPAS.config.mdcMasters?.sealId ?? 0);
            if (this.uf) {
                query.mdcUf = `EQ(${this.uf})`;
            }
            if (this.city) {
                query.mdcCidade = `ILIKE(*${this.city.replace(/[*()]/g, '')}*)`;
            }
            if (acting) {
                query.mdcAtuacao = `ILIKE(*|${acting}|*)`;
            }

            return query;
        },
    },

    watch: {
        segment() {
            this.typology = '';
        },
    },

    methods: {
        card(entity) {
            const text = (entity.shortDescription || entity.longDescription || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

            return {
                name: entity.name,
                knownAs: entity.mdcNomeArtistico,
                activities: entity.mdcAtividades,
                city: entity.mdcCidade,
                summary: text.length > 160 ? `${text.slice(0, 159).trim()}…` : text,
                avatar: entity.files?.avatar?.transformations?.avatarMedium?.url ?? entity.files?.avatar?.url ?? null,
                url: entity.singleUrl,
            };
        },

        clear() {
            this.uf = '';
            this.city = '';
            this.segment = '';
            this.pseudoQuery['@keyword'] = '';
        },
    },
});
