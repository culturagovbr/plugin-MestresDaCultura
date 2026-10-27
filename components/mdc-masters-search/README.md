# Componente `<mdc-masters-search>`

Busca pública de Mestras e Mestres (`/mestras-e-mestres/`, F5-04), dentro do `search` do core: o filtro do core (`search-filter`, com a busca por nome) e os campos do programa (UF, cidade, segmento e tipologia da atuação), e a lista paginada da API do core (`mc-entities`) só com quem tem o selo do programa, nos cartões da home (`mdc-master-card`).

- Os filtros leem os dados públicos que o plugin grava no agente na aprovação (`MDC\Services\MasterPublicData`); a busca por nome também procura o nome artístico.
- A UF pode vir no endereço (`?uf=PE`), como no mapa da home.
- O selo vem de `$MAPAS.config.mdcMasters.sealId`, posto pela rota `mdc/masters`.
- O visual fica no tema MestresDaCultura (`_mdc-masters-search.scss`).

## Propriedades

- *Object **pseudoQuery*** - a consulta do `search`.
