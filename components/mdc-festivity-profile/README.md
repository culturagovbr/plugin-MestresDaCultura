# Componente `<mdc-festivity-profile>`

Página pública do festejo (tela 2 do protótipo), sobre a página de evento do core, como o perfil da mestra (`mdc-master-profile`): só acrescenta; a agenda, a descrição, as fotos, os vídeos e os links seguem os do core.

- Não é usado direto nas telas: o plugin o monta nos ganchos da página de evento (`MDC\Plugin::showFestivityProfile`), só em festejo do subsite dos Mestres, com os dados de `MDC\Services\FestivityProfile`, que só tem o que é público.
- `header`, no fim do cabeçalho: o selo do Calendário, a classificação, o lugar, o porte, o ano e as edições.
- `main`, antes dos vídeos: quando e onde acontece, as mestras e os mestres da Rede (com o perfil de cada um), acessibilidade e acolhimento, a economia da cultura e o Ponto de Cultura parceiro.
- `aside`, antes das verificações: o certificado e "Quem realiza", com o contato que a pessoa autorizou.
- O visual fica no tema MestresDaCultura (`_mdc-festivity-profile.scss`), que esconde o "Publicado por" (o agente não tem página no subsite) e as verificações do core (o certificado já está aqui).

## Propriedades

- *String **part*** - `header`, `main` ou `aside`.
- *Object **profile*** - os dados públicos do festejo.
