# Componente `<mdc-tree-node-panel>`

Painel do item escolhido na curadoria da Árvore de Atuação: caminho, nível, se está ativo, quantos cadastros enviados e rascunhos o marcaram, quantos itens há no ramo, a lista dos itens logo abaixo e a ordem entre os irmãos.

- A ordem muda entre os irmãos ativos, os que aparecem no formulário (como em `TreeCuration::reorder`).
- Na raiz (id 0), mostra o resumo da árvore e a lista dos segmentos.
- Ações: renomear, adicionar abaixo (ou segmento, na raiz), mover (destino escolhido por busca, só entre os válidos pelas regras de `TreeCuration::move`) e desativar, cada uma num `<mc-modal>` que explica a regra e só fecha quando deu certo; reativar é direto, e fica bloqueado com o item acima desativado.
- Possíveis duplicatas (`TreeDuplicates`): lista os outros itens com o mesmo nome, ou quase, e oferece "É repetição legítima"; marcado, mostra "Voltar a alertar".
- Equivalências (`TreeEquivalence`): mostra a referência do item, ou os itens que equivalem a ele; "Marcar como equivalente…" abre a busca já com o nome do item (o caso comum é a duplicata em outro ramo) e só lista referências válidas, sem cadeias.
- Histórico (`TreeHistory`): bloco recolhido que, ao abrir, mostra quem mudou o quê e quando no item.
- Não chama rotas: usa a página (`inject: ['curation']`, o `<mdc-tree-curation>`), que faz a ação e relê a árvore.
- O visual fica no tema MestresDaCultura (`_mdc-tree-node-panel.scss`).

## Propriedades
- *Object **node*** - Item da árvore da curadoria: `{id, name, enabled, sent, drafts, children}`;
- *Object **parent** = null* - Pai do item (a raiz, para os segmentos);
- *Array **path*** - Nomes da raiz até o item, inclusive;
- *Boolean **busy** = false* - Ação em andamento: os botões esperam.

## Eventos
- **select** - Id do item clicado na lista dos itens abaixo.

### Importando componente

```PHP
<?php $this->import('mdc-tree-node-panel'); ?>
```

### Usando o componente

```HTML
<mdc-tree-node-panel :node="node" :parent="parent" :path="path" @select="focus"></mdc-tree-node-panel>
```
