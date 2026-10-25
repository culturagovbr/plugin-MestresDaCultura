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
})();
