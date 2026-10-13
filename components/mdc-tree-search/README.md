# Componente `<mdc-tree-search>`

Busca de itens na curadoria da Árvore de Atuação: a partir de duas letras, lista até 12 itens com o caminho de cada um, sem diferenciar maiúsculas nem acentos. Itens desativados aparecem marcados.

- Escolher um resultado emite `select`; a página repassa ao `<mdc-tree-map>`, que abre o caminho e centraliza o item.
- A cada busca emite `matches`, com os ids encontrados, para o mapa destacar os que estão à vista.
- O campo segue o `.field` do BaseV2; o visual da lista fica no tema (`_mdc-tree-search.scss`).

## Propriedades
- *Array **tree*** - Segmentos da árvore da curadoria: `{id, name, enabled, children}`.

## Eventos
- **select** - Id do item escolhido;
- **matches** - Ids dos itens que batem com a busca (vazio quando a busca tem menos de duas letras).

### Importando componente

```PHP
<?php $this->import('mdc-tree-search'); ?>
```

### Usando o componente

```HTML
<mdc-tree-search :tree="tree" @select="selected = $event" @matches="matches = $event"></mdc-tree-search>
```
