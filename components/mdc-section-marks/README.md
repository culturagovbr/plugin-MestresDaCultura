# Componente `<mdc-section-marks>`

Marca cada seção do formulário de inscrição com o nome dela, sem acento, caixa e pontuação (`data-mdc-section="marcadores-sociais"`), para o tema pôr o ícone de cada seção, como no protótipo. Os cartões de seção do core não têm classe própria e o CSS não lê o título.

- Não desenha nada; observa o formulário, que troca as seções a cada etapa.
- Não é usado direto nas telas: o plugin o monta no fim do `registration-form` (gancho `component(registration-form):end`), só no subsite dos Mestres.
- Os ícones ficam no tema MestresDaCultura (`_registration-form-sections.scss`).

### Importando componente

```PHP
<?php $this->import('mdc-section-marks'); ?>
```

### Usando o componente

```HTML
<mdc-section-marks></mdc-section-marks>
```
