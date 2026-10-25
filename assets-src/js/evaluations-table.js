// Padrão de colunas da lista de avaliações do programa (ver Services\EvaluationsTable), com a data de envio à vista;
// um padrão salvo para a oportunidade vale no lugar deste.
(function () {
    const config = $MAPAS.config.mdcEvaluationsTable;
    const tables = $MAPAS.config.entityTable?.columnsConfig?.tables;

    if (!config || !tables) {
        return;
    }

    for (const identifier of config.tables) {
        const key = `opportunity-findEvaluations-${config.opportunity}-${identifier}`;
        tables[key] ??= { visible: config.visible };
    }

    // Tira da tela as colunas que o programa não usa, inclusive o coletivo e as metas que o componente acrescenta.
    const component = app.component('opportunity-evaluations-table');
    const headers = component?.computed?.headers;

    if (headers) {
        component.computed.headers = function () {
            return headers.call(this).filter((header) => !config.hidden.includes(header.slug));
        };
    }
})();
