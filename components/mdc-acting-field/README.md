# Componente `<mdc-acting-field>`

Campo da pergunta "Atuação Cultural" do cadastro: a árvore Segmento › Tipologia › Subtipologia › Atividade, com seleção múltipla e um "Outro" em texto livre em cada lista. É o campo do tipo `mdc-atuacao` (`MDC\Services\ActingField`).

- Não é usado direto nas telas: o plugin o injeta no `<entity-field>` pelo gancho `template(<<*>>.<<*>>.entity-field-label):after` quando o campo é do tipo `mdc-atuacao`. Rótulo, descrição, obrigatoriedade e erros continuam vindo do `<entity-field>`.
- A árvore vem da rota `GET mdc/tree`, só quando o campo aparece.
- Marcar um item marca os pais; desmarcar leva os filhos e os "Outro" de dentro deles.
- Um segmento por `<mc-accordion>`, aberto quando marcado; cada nível abaixo é um `<mdc-acting-list>`.
- O valor emitido é `{nodes: [ids], others: [{parent, text}]}`, com os ids na ordem da árvore, ou `null` quando não há nada marcado (é o que faz a obrigatoriedade do core funcionar). `parent: null` é o "Não encontrou sua atuação na lista?".
- Ids que saíram da árvore (desativados pela curadoria) são desmarcados ao carregar, com aviso.
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
