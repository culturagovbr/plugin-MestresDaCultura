# Componente `<mdc-tree-others>`

Fila "Não encontrou?" da curadoria da Árvore de Atuação: os textos de "Outro" das respostas, agrupados pelo lugar onde foram escritos (`GET mdc/curationOthers`, ver `MDC\Services\OtherQueue`), com quantos cadastros enviados e rascunhos os escreveram e os itens parecidos.

- **Criar item…** cria o item com o texto (o nome pode ser corrigido) no lugar onde foi escrito ou em outro, escolhido por busca.
- **É o mesmo que…** associa o texto a um item que já existe; as sugestões vêm primeiro.
- **Arquivar…** tira o texto da fila, com motivo opcional.
- Nenhuma decisão altera os cadastros. Os textos já tratados ficam em cartões à parte: a decisão, o item (que leva ao mapa) ou o motivo, e a data.
- Usa a página (`inject: ['curation']`, o `<mdc-tree-curation>`) para a árvore, as ações (`act`) e para ir até um item no mapa (`goTo`).
- O visual fica no tema MestresDaCultura (`_mdc-tree-others.scss`).

## Eventos
- **count** - Quantos textos esperam decisão (para o rótulo da aba).

### Importando componente

```PHP
<?php $this->import('mdc-tree-others'); ?>
```

### Usando o componente

```HTML
<mdc-tree-others @count="pendingOthers = $event"></mdc-tree-others>
```
