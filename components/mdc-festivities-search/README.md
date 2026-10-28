# Componente `<mdc-festivities-search>`

Busca pública de festejos (`/festejos-populares/`), dentro do `search` do core, como a de Mestras e Mestres: o filtro do core (busca por nome) e os do programa (mês da agenda, UF, cidade, segmento, tipologia, porte, com Mestras e Mestres da Rede e com acessibilidade), e a lista paginada da API de eventos, só com quem tem o selo dos festejos, em cartões (`mdc-festivity-card`).

- O selo, as listas e o parâmetro do mês vêm de `$MAPAS.config.mdcFestivities`, posto pela rota `mdc/festivities`.
- O mês não é um campo: o servidor troca o parâmetro pelos festejos com algum período no mês (`MDC\Services\FestivitySearch`).
- As tipologias ficam no JSON sem escapar acentos e barras (`MDC\Services\FestivityFields`), para o filtro achar "Festas de Santos/Devocional".
- O visual fica no tema MestresDaCultura (`_mdc-festivities-search.scss`).

## Propriedades

- *Object **pseudoQuery*** - a consulta do `search`.
