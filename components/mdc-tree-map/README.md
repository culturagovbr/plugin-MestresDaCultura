# Componente `<mdc-tree-map>`

Mapa mental da Árvore de Atuação na curadoria: "Atuação Cultural" no centro e os segmentos dos dois lados, na ordem do formulário (a primeira metade à direita, o resto à esquerda).

- O SVG é montado pelo Vue; o d3 (`TreeMapD3`, de `assets-src/js/tree-map-d3.js`) só calcula as posições e cuida do zoom e do arraste.
- Cada ramo abre e fecha no círculo ao lado do item ("+N" quando fechado). Clicar no item o escolhe e abre o ramo dele.
- Mostra, ao lado do nome, quantos cadastros enviados marcaram o item. Desativados aparecem tracejados e riscados.
- A largura de cada caixa é medida com a fonte e o tamanho do `_mdc-tree-map.scss` do tema; se um mudar, o outro acompanha.
- O visual fica no tema MestresDaCultura (`_mdc-tree-map.scss`), com uma cor por segmento.

## Propriedades
- *Array **tree*** - Segmentos da árvore da curadoria (`GET mdc/curationTree`): `{id, name, enabled, sent, drafts, children}`;
- *Number **selected** = null* - Item escolhido; ao mudar, o mapa abre o caminho até ele e o centraliza;
- *Boolean **showDisabled** = true* - Mostra os itens desativados;
- *Array **highlight** = []* - Ids a destacar, como os encontrados pela busca.

## Eventos
- **select** - Id do item clicado (0 é a raiz).

## Métodos
- **openBranch(id)** - Abre o item com tudo o que está abaixo e enquadra o ramo;
- **closeAll()** - Fecha todos os ramos;
- **fit()** - Enquadra o mapa inteiro.

### Importando componente

```PHP
<?php $this->import('mdc-tree-map'); ?>
```

### Usando o componente

```HTML
<mdc-tree-map ref="map" :tree="tree" :selected="selected" @select="selected = $event"></mdc-tree-map>
```
