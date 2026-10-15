# Componente `<mdc-master-profile>`

Perfil público do mestre aprovado (tela 9 do protótipo) sobre a página de agente do core, sem trocar arquivos dele: o cabeçalho tem como título o nome artístico (ou o nome) e mostra a atuação, a cidade e desde quando integra a Rede; a coluna lateral ganha o quadro do certificado; a principal, a atuação cultural. O tema esconde, só nessa página, o que o protótipo não mostra.

- Os dados vêm do plugin (`MDC\Services\MasterProfile`), que só monta o componente na página de um agente com o selo do programa. A atuação é o HTML da ficha (`MDC\Services\ActingField::toHtml`), já escapado.
- Não é usado direto nas telas: o plugin o monta pelos ganchos `template(agent.single.entity-header):end`, `template(agent.single.single1-entity-info-taxonomie-area):before` e `template(agent.single.entity-gallery-video):before`, um por parte.
- O visual fica no tema MestresDaCultura (`_mdc-master-profile.scss`).

## Propriedades

- *Object **profile*** - `{name, knownAs, activities, city, since, certificateUrl, actingHtml}`
- *String **part*** - `'header'`, `'certificate'` ou `'acting'`

### Importando componente

```PHP
<?php $this->import('mdc-master-profile'); ?>
```

### Usando o componente

```HTML
<mdc-master-profile part="certificate" :profile="profile"></mdc-master-profile>
```
