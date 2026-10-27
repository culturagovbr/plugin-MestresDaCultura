# Componente `<mdc-festivity-field>`

Campo "Identifique o festejo no calendário" do cadastro de Mestras e Mestres (item 24.4 da V3): busca entre os festejos publicados no Calendário e grava o id do escolhido. Depois de escolhido, mostra o festejo com o link para a página dele.

- Não é usado direto nas telas: o plugin o monta dentro do `entity-field` do core nos campos do tipo `mdc-festejo` (`MDC\Services\FestivityField`), como o da atuação.
- A resposta em texto, de antes da busca, aparece como "Resposta anterior" até a mestra escolher um festejo.
- Sem nenhum festejo com o nome, oferece o cadastro do festejo numa aba nova.
- Com `disabled` (a ficha), só mostra.

## Propriedades

- *Entity **entity*** - a inscrição.
- *String **prop*** - o campo (`field_400`).
- *Boolean **disabled*** - só leitura.

## Eventos

- **change** - o id do festejo escolhido, ou `''` ao trocar.
