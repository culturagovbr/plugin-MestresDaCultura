# Componente `<mdc-festivity-classification>`

Classificação do festejo na etapa 1 do cadastro (item 3 da planilha V2): um segmento e até duas tipologias dele. O "Outro" escrito conta como uma das duas.

O desenho é o da Atuação Cultural dos Mestres (`mdc-acting-field`): as etiquetas de "Selecionados" com o x, um acordeão numerado por segmento (aqui com rádio, porque é um só) e as tipologias em `.check-row`.

- A lista vem de `data/festejos-classificacao.json`, por `$MAPAS.config.mdcFestivity.classification`; as chaves dos campos, de `classificationKeys`.
- Trocar o segmento limpa as tipologias e o "Outro".
- O servidor confere a mesma regra mesmo no rascunho (`MDC\Services\FestivityForm::invalid`).

## Propriedades

- *Entity **entity*** - o festejo.
- *Boolean **required*** - marca "obrigatório".
