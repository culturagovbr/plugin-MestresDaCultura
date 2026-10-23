# Componente `<mdc-age>`

Idade calculada da data de nascimento, logo abaixo do campo no formulário de inscrição (pergunta 3.1 da planilha V3). Só exibe: a idade não é gravada; a planilha de inscrições calcula a dela na exportação (`MDC\Services\RegistrationsSpreadsheet`).

- Não é usado direto nas telas: o plugin o monta no fim do `entity-field` (gancho `template(<<*>>.<<*>>.entity-field):end`), no campo do formulário que grava em `dataDeNascimento`, só no subsite dos Mestres.

## Propriedades

- *McDate|String **value*** - a data de nascimento

### Importando componente

```PHP
<?php $this->import('mdc-age'); ?>
```

### Usando o componente

```HTML
<mdc-age :value="entity[prop]"></mdc-age>
```
