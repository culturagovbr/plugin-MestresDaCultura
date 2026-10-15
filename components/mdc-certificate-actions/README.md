# Componente `<mdc-certificate-actions>`

Barra da página de certificado do selo do programa (`seal/sealRelation/{id}`), que é a do core: "Voltar" e "Imprimir ou salvar em PDF". A página do core não tem botão de impressão e o "Voltar" dela aponta para `#`.

- A impressão é a do navegador; a folha de impressão do tema deixa só o certificado (o `#print` do core) na página.
- Não é usado direto nas telas: o plugin o monta depois do cabeçalho do site (gancho `template(seal.sealRelation.main-header):after`), só no certificado do selo do programa (`MDC\Services\ProgramSeal`).
- O visual do certificado e da barra fica no tema MestresDaCultura (`_seal-relation-view.scss`).

### Importando componente

```PHP
<?php $this->import('mdc-certificate-actions'); ?>
```

### Usando o componente

```HTML
<mdc-certificate-actions></mdc-certificate-actions>
```
