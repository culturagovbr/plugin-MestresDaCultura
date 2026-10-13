# Componente `<mdc-tree-list>`

A Árvore de Atuação em lista, alternativa ao `<mdc-tree-map>` na curadoria: acessível por teclado e leitor de tela (padrão de árvore do WAI-ARIA) e a forma padrão em telas estreitas.

- Setas para cima e para baixo andam pelos itens à vista; para a direita abre o ramo (ou vai ao primeiro filho), para a esquerda fecha (ou vai ao pai); Home e End vão ao primeiro e ao último; Enter ou espaço escolhem o item.
- Só o item com o foco entra na tabulação (tabindex móvel); ao mudar a seleção, a lista abre o caminho até o item.
- Mostra o mesmo que o mapa: cadastros enviados, desativados, possíveis duplicatas e os itens encontrados pela busca.
- O visual fica no tema MestresDaCultura (`_mdc-tree-list.scss`).

## Propriedades
- *Array **tree*** - Segmentos da árvore da curadoria;
- *Number **selected** = null* - Item escolhido;
- *Boolean **showDisabled** = true* - Mostra os itens desativados;
- *Boolean **showDuplicates** = true* - Marca as possíveis duplicatas;
- *Array **highlight** = []* - Ids a destacar, como os encontrados pela busca.

## Eventos
- **select** - Id do item escolhido.

### Importando componente

```PHP
<?php $this->import('mdc-tree-list'); ?>
```

### Usando o componente

```HTML
<mdc-tree-list :tree="tree" :selected="selected" @select="selected = $event"></mdc-tree-list>
```
