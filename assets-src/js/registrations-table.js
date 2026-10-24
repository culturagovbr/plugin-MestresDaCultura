// Padrão de colunas da lista de cadastros do programa (ver Services\RegistrationsTable): o do core sem as colunas que o
// programa não usa e com as novas no lugar; um padrão salvo para a oportunidade vale no lugar deste.
(function () {
    const config = $MAPAS.config.mdcRegistrationsTable;
    const tables = $MAPAS.config.entityTable?.columnsConfig?.tables;
    const core = tables?.['opportunity-findRegistrations-registrationsList'];
    const key = `opportunity-findRegistrations-${$MAPAS.requestedEntity?.id}-registrationsList`;

    if (!config || !core || tables[key]) {
        return;
    }

    const place = (slugs) => {
        let result = slugs.filter((slug) => !config.hidden.includes(slug));

        for (const [slug, after] of Object.entries(config.after)) {
            result = result.filter((item) => item !== slug);
            const index = result.indexOf(after);
            result.splice(index < 0 ? result.length : index + 1, 0, slug);
        }

        return result;
    };

    // `known`: as escondidas ficam fora da lista de visíveis do componente.
    tables[key] = { visible: place(core.visible || []), order: place(core.order || []), known: config.hidden };
})();
