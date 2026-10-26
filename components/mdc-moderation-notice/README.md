# Componente `<mdc-moderation-notice>`

Resultado da moderação para o mestre. Em análise: o que acontece depois do envio. Devolvido (tela 8 do protótipo): o resultado de cada campo moderado, aprovado ou não comprovado, com o motivo, e o botão que abre a edição do core já na etapa do primeiro campo recusado. Aprovado: o botão do certificado, a página do selo do programa (`MDC\Services\ProgramSeal`).

- Os dados vêm do plugin, que só monta o aviso para o dono, enquanto o cadastro está em análise (`MDC\Services\Moderation::underReview`) ou pendente (`MDC\Services\Moderation::pendingView`), ou depois de aprovado com o selo. O nome de quem avaliou não vai.
- Não é usado direto nas telas: o plugin o monta no espaço de aviso do `mc-tabs` da página da inscrição, pelo gancho `template(registration.view.single-tab):begin`.
- A troca do documento e o reenvio são os da edição do core, que libera só os campos recusados.
- O visual fica no tema MestresDaCultura (`_mdc-moderation-notice.scss`).

## Propriedades

- *Object **moderation*** - `{status: 'review'}`, `{status: 'pending', fields: [{name, label, section, result: 'valid'|'invalid', obs}], editUrl}` ou `{status: 'approved', certificateUrl}`

### Importando componente

```PHP
<?php $this->import('mdc-moderation-notice'); ?>
```

### Usando o componente

```HTML
<mdc-moderation-notice :moderation="{fields: [...], editUrl: '...'}"></mdc-moderation-notice>
```
