# Componente `<mdc-acting-field>`

Campo da pergunta "Atuação Cultural" do cadastro: a árvore Segmento › Tipologia › Subtipologia › Atividade, com seleção múltipla e um "Outro" em texto livre em cada lista. É o campo do tipo `mdc-atuacao` (`MDC\Services\ActingField`).

- Não é usado direto nas telas: o plugin o injeta no `<entity-field>` pelo gancho `template(<<*>>.<<*>>.entity-field-label):after` quando o campo é do tipo `mdc-atuacao`. Rótulo, descrição, obrigatoriedade e erros continuam vindo do `<entity-field>`.
- A árvore vem da rota `GET mdc/tree`, só quando o campo aparece.
- Marcar um item marca os pais; desmarcar leva o que está abaixo dele e os "Outro" de dentro.
- Um segmento por `<mc-accordion>`, aberto quando marcado; cada nível abaixo é um `<mdc-acting-list>`.
- A resposta é um espelho do que foi escolhido, para não depender da árvore viva: `{nodes: [{id, parent, name}], others: [{parent, text}]}`, ou `null` quando não há nada marcado (é o que faz a obrigatoriedade do core funcionar). `parent: null` em `others` é o "Não encontrou sua atuação na lista?". Os nomes gravados vêm sempre do servidor.
- **Rascunho** segue a árvore atual: nomes atualizados; o que a curadoria tirou da lista é desmarcado ao abrir, com o que estava abaixo, e um aviso diz o que saiu; item mudado de lugar continua marcado, com o caminho novo.
- **Inscrição enviada** mostra o espelho gravado, mesmo que a árvore tenha mudado depois.
- O visual fica no tema MestresDaCultura (`_mdc-acting-field.scss`, `_check-row.scss`, `_subtipologia-box.scss`).

## Propriedades
- *Entity **entity*** - Inscrição que guarda a resposta;
- *String **prop*** - Nome do campo na inscrição (`field_<id>`);
- *Boolean **disabled** = false* - Só exibe, sem permitir mudanças.

## Eventos
- **change** - Valor novo, no formato acima. O `<entity-field>` o recebe como `change({ target: { value } }, true)` e segue com o salvamento automático.

### Importando componente

```PHP
<?php $this->import('mdc-acting-field'); ?>
```

### Usando o componente

```HTML
<mdc-acting-field :entity="registration" prop="field_326" @change="..."></mdc-acting-field>
```
