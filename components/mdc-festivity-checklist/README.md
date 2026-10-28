# Componente `<mdc-festivity-checklist>`

Lista de caixas de marcar com "Outro", da etapa 3 do cadastro do festejo: economia criativa (item 16), acessibilidade (19) e acolhimento (20).

- Os grupos e os itens vêm de `MDC\Services\FestivityForm::lists()`, por `$MAPAS.config.mdcFestivity.lists`.
- O desenho é o da Atuação Cultural dos Mestres (`mdc-acting-field`): as etiquetas de "Selecionados" com o x e as opções em `.check-row`. O grupo com nome vira um acordeão numerado, aberto se já tem item marcado, com quantos tem; a lista sem grupo fica aberta.
- O "Outro" é um campo de texto, gravado em `other-prop`; o x da etiqueta apaga o texto.

## Propriedades

- *Entity **entity*** - o festejo.
- *String **prop*** - o campo da lista (multiselect).
- *String **otherProp*** - o campo do "Outro".
- *Array **groups*** - `[{label, items: [{value, label}]}]`.
- *String **title***, *String **description*** - o título e a ajuda.
- *Boolean **required*** - marca "obrigatório".
