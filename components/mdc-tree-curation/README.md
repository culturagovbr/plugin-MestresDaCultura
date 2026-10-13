# Componente `<mdc-tree-curation>`

Página de curadoria da Árvore de Atuação (`painel/mdc-tree-curation`, só para administradores do subsite do programa).

- Lê a árvore com os desativados e o uso de cada item em `GET mdc/curationTree`.
- Barra com a busca (`<mdc-tree-search>`, que leva ao item e destaca no mapa os encontrados), "Mostrar desativados", "Abrir ramo selecionado", "Fechar tudo" e "Enquadrar".
- O mapa é o `<mdc-tree-map>`; o painel ao lado é o `<mdc-tree-node-panel>`, do item escolhido.
- As ações passam por `act()`, que chama a rota `mdc/curation*`, mostra a mensagem do sistema (a recusa vem com o texto da regra) e relê a árvore sem perder a seleção nem os ramos abertos. Quando a área de conteúdo é estreita (o menu lateral do painel ocupa espaço), o painel vai para baixo do mapa.
- O visual fica no tema MestresDaCultura (`_mdc-tree-curation.scss`).

### Importando componente

```PHP
<?php $this->import('mdc-tree-curation'); ?>
```

### Usando o componente

```HTML
<mdc-tree-curation></mdc-tree-curation>
```
