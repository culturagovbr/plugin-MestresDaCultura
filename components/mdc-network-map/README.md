# Componente `<mdc-network-map>`

Mapa da Rede na home (F5-05, como o protótipo): o Brasil por estado, com a quantidade de mestras e mestres em cada UF, sem a localização de ninguém (D14). Cada estado com alguém leva à busca de Mestras e Mestres filtrada pela UF (`?uf=PE`). Ao lado, a lista dos estados com alguém, os com mais gente primeiro: dez à vista e o resto em "Ver todos os estados", numa segunda coluna ao lado (no celular, embaixo); é também a alternativa acessível ao desenho. E a legenda das faixas.

- Os dados vêm de `$MAPAS.config.mdcShowcase`, que o plugin põe na home: `map` (o desenho dos estados, `data/br-uf.json`, da API de malhas do IBGE), `states` (`MDC\Services\MasterShowcase::byState`) e `searchUrl`.
- O visual fica no tema MestresDaCultura (`_mdc-network-map.scss`).
