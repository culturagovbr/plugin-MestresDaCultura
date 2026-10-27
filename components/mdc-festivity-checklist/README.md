# Componente `<mdc-festivity-checklist>`

Lista de caixas de marcar com "Outro", da etapa 3 do cadastro do festejo: economia criativa (item 16), acessibilidade (19) e acolhimento (20).

- Os grupos e os itens vêm de `MDC\Services\FestivityForm::lists()`, por `$MAPAS.config.mdcFestivity.lists`.
- Com `collapsible`, cada grupo abre à parte e mostra quantos itens tem marcados, como a planilha pede na economia (o segmento e depois as ocupações).
- O "Outro" abre o campo de texto, gravado em `other-prop`; desmarcar apaga o texto.

## Propriedades

- *Entity **entity*** - o festejo.
- *String **prop*** - o campo da lista (multiselect).
- *String **otherProp*** - o campo do "Outro".
- *Array **groups*** - `[{label, items: [{value, label}]}]`.
- *String **title***, *String **description*** - o título e a ajuda.
- *Boolean **collapsible*** - um grupo aberto por vez.
- *Boolean **required*** - marca "obrigatório".
