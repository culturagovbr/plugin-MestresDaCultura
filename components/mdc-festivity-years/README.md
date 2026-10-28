# Componente `<mdc-festivity-years>`

Os anos em que o festejo recebeu recursos de uma fonte (itens 21 a 24 da planilha, etapa 3 do cadastro), marcados em botões em vez de digitados.

- Os botões vão do ano atual até o ano da primeira realização (etapa 1); sem ele, os últimos 15.
- Grava no mesmo campo de texto de antes, em ordem ("2023, 2024"): a página do festejo e a planilha não mudam, e o texto livre de festejos antigos continua lido.
- O servidor confere o formato e que nenhum ano passa do atual (`MDC\Services\FestivityForm::invalid`).

## Propriedades

- *Entity **entity*** - o festejo.
- *String **prop*** - o campo dos anos (`mdcFestejoRouanetAnos`, …).
- *Boolean **required*** - marca "obrigatório".
