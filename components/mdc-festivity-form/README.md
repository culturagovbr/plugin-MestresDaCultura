# Componente `<mdc-festivity-form>`

Cadastro do festejo em 6 etapas (`/cadastrar-festejo/`), sobre o Evento do core, com a barra de etapas do cadastro dos Mestres (`mc-stepper`).

- Começa um festejo novo ou continua o de `$MAPAS.requestedEntity` (`/cadastrar-festejo/{id}/`).
- "Salvar e avançar" grava o rascunho e confere a etapa em `mdc/festivityCheck`; o que falta aparece na lista e nos campos. Antes do primeiro rascunho, só a etapa 1 abre.
- "Publicar festejo" publica pelo core, que cobra as 6 etapas (`MDC\Services\FestivityForm`).
- As etapas, o que cada uma cobra e as rotas vêm de `$MAPAS.config.mdcFestivity`.
- O visual fica no tema MestresDaCultura (`_mdc-festivity-form.scss`).
