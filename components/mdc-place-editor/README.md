# Componente `<mdc-place-editor>`

A janela de cadastrar ou editar um local de festejo: o nome e o endereço (`entity-location` do core), o mesmo formulário do local novo da etapa 2. Usada em "Meus locais" (`mdc-places`) e no "Editar local" de cada período da agenda (`mdc-festivity-agenda`).

- `open(id)` carrega o local, rascunho incluído, e abre a janela; gravar segue a regra do core: só a dona.
- `create()` abre a janela vazia para um local novo de quem cadastra, em rascunho como os da etapa 2.
- Ao salvar, emite `saved` com o local; os festejos dele atualizam a UF e a cidade no servidor.

## Eventos

- **saved** - o local (Entity) depois de gravado.
