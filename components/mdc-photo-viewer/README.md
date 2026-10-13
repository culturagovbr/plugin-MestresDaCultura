# Componente `<mdc-photo-viewer>`

Exibe, num `<mc-modal>`, a foto do agente clicada na ficha da inscrição (as fotos que o plugin monta no lugar do `[object Object]` do core; ver `MDC\Services\AgentMediaView`).

- A ficha é uma tela do v1 dentro de um iframe que cresce com o conteúdo: um modal aberto lá dentro ficaria fora da tela. Por isso a ficha só avisa o clique, por `postMessage` (`assets-src/js/plugin-MDC.js`), e o modal abre aqui, na página de cima.
- Aceita apenas mensagens do próprio site (`event.origin`) e fotos com endereço http(s).
- Com mais de uma foto, mostra "Foto 2 de 3" no título e os botões Anterior e Próxima; as setas do teclado também navegam. Fechar, Esc e clique fora vêm do `<mc-modal>`.
- Não é usado direto nas telas: o plugin o monta em toda página pelo gancho `template(<<*>>.<<*>>.main-header):after`.
- O visual fica no tema MestresDaCultura (`_mdc-photo-viewer.scss`).

## Mensagem

```JS
window.parent.postMessage({
    type: 'mdc.photos.open',
    data: { photos: [{ src: 'https://…/foto.jpg', alt: 'Roda de coco' }], index: 0 },
}, window.location.origin);
```

### Importando componente

```PHP
<?php $this->import('mdc-photo-viewer'); ?>
```

### Usando o componente

```HTML
<mdc-photo-viewer></mdc-photo-viewer>
```
