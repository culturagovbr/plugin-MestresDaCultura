# Componente `<mdc-acting-list>`

Um nível da árvore de atuação: os filhos de um nó, cada um numa linha `.check-row`, e o campo "Outro" daquele nível ao fim, no padrão `.field` dos demais campos. Quando um item marcado tem filhos, abre debaixo dele uma `.subtipologia-box` com outro `<mdc-acting-list>`.

Não guarda estado: lê e altera a seleção pelo `<mdc-acting-field>` que o contém (`inject: ['acting']`). Só funciona dentro dele, que é também quem o importa.

## Propriedades
- *Object **parent*** - Nó cujos filhos são listados (`{id, name, children}`, como vem de `GET mdc/tree`).

### Usando o componente

```HTML
<mdc-acting-list :parent="segment"></mdc-acting-list>
```
