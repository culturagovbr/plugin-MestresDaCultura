app.component('mdc-festivities-search', {
    template: $TEMPLATES['mdc-festivities-search'],

    props: {
        // a consulta do `search` do core: daqui sai a busca por nome (@keyword)
        pseudoQuery: {
            type: Object,
            required: true,
        },
    },

    data() {
        const config = $MAPAS.config.mdcFestivities;

        return {
            month: '',
            uf: '',
            city: '',
            segment: '',
            typology: '',
            size: '',
            withMasters: false,
            accessible: false,
            classification: config.classification,
            sizes: config.sizes,
            formUrl: config.formUrl,
            months: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
            ufs: ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'],
            select: 'id,name,singleUrl,files.avatar,mdcFestejoSegmento,mdcFestejoTipologias,mdcFestejoCidade,mdcFestejoUf,mdcFestejoPorte,mdcFestejoPrimeiroAno,mdcFestejoAgenda',
        };
    },

    computed: {
        typologies() {
            return this.classification.find((item) => item.segmento === this.segment)?.tipologias ?? [];
        },

        // Só os publicados, que têm o selo dos festejos; o resto são os campos do festejo (MDC\Services\FestivityFields).
        query() {
            const config = $MAPAS.config.mdcFestivities;
            const clean = (value) => value.replace(/[*()]/g, '');
            const query = Utils.parsePseudoQuery({ '@keyword': this.pseudoQuery['@keyword'] || undefined });

            query['@seals'] = String(config.sealId ?? 0);
            if (this.month) {
                query[config.monthParam] = String(this.month);
            }
            if (this.uf) {
                query.mdcFestejoUf = `EQ(${this.uf})`;
            }
            if (this.city) {
                query.mdcFestejoCidade = `ILIKE(*${clean(this.city)}*)`;
            }
            if (this.segment) {
                query.mdcFestejoSegmento = `EQ(${this.segment})`;
            }
            if (this.typology) {
                query.mdcFestejoTipologias = `ILIKE(*"${clean(this.typology)}"*)`;
            }
            if (this.size) {
                query.mdcFestejoPorte = `EQ(${this.size})`;
            }
            if (this.withMasters) {
                query.mdcFestejoMestres = 'ILIKE(*|*)';
            }
            if (this.accessible) {
                query.mdcFestejoTemAcessibilidade = 'EQ(Sim)';
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
            const typologies = Array.isArray(entity.mdcFestejoTipologias) ? entity.mdcFestejoTipologias : [];

            return {
                name: entity.name,
                url: entity.singleUrl,
                avatar: entity.files?.avatar?.transformations?.avatarMedium?.url ?? entity.files?.avatar?.url ?? null,
                // Só a data do primeiro período, sem o horário ("3 a 5 de dezembro de 2026").
                agenda: (entity.mdcFestejoAgenda || '').split(', ')[0],
                classification: [typologies[0] || entity.mdcFestejoSegmento, [entity.mdcFestejoCidade, entity.mdcFestejoUf].filter(Boolean).join(', ')].filter(Boolean).join(' · '),
                details: [entity.mdcFestejoPorte ? `Porte ${entity.mdcFestejoPorte.toLowerCase()}` : '', entity.mdcFestejoPrimeiroAno ? `desde ${entity.mdcFestejoPrimeiroAno}` : ''].filter(Boolean).join(' · '),
            };
        },

        clear() {
            this.month = '';
            this.uf = '';
            this.city = '';
            this.segment = '';
            this.size = '';
            this.withMasters = false;
            this.accessible = false;
            this.pseudoQuery['@keyword'] = '';
        },
    },
});
