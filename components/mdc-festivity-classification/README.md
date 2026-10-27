# Componente `<mdc-festivity-classification>`

Classificação do festejo na etapa 1 do cadastro (item 3 da planilha V2): um segmento e até duas tipologias dele. O "Outro" abre o campo de texto e conta como uma das duas.

- A lista vem de `data/festejos-classificacao.json`, por `$MAPAS.config.mdcFestivity.classification`; as chaves dos campos, de `classificationKeys`.
- Trocar o segmento limpa as tipologias e o "Outro".
- O servidor confere a mesma regra mesmo no rascunho (`MDC\Services\FestivityForm::invalid`).

## Propriedades

- *Entity **entity*** - o festejo.
- *Boolean **required*** - marca "obrigatório".
