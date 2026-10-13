# Componente `<mdc-tree-curation>`

Página de curadoria da Árvore de Atuação (`painel/mdc-tree-curation`, só para administradores do subsite do programa).

- Lê a árvore com os desativados e o uso de cada item em `GET mdc/curationTree`.
- Barra com a busca (`<mdc-tree-search>`, que leva ao item e destaca no mapa os encontrados), "Mostrar desativados", "Abrir ramo selecionado", "Fechar tudo" e "Enquadrar".
- O mapa é o `<mdc-tree-map>`; o painel ao lado mostra o item escolhido. Quando a área de conteúdo é estreita (o menu lateral do painel ocupa espaço), o painel vai para baixo do mapa.
- O visual fica no tema MestresDaCultura (`_mdc-tree-curation.scss`).

### Importando componente

```PHP
<?php $this->import('mdc-tree-curation'); ?>
```

### Usando o componente

```HTML
<mdc-tree-curation></mdc-tree-curation>
```
