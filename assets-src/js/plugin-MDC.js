// Ficha da inscrição (telas de leitura do v1): a foto do agente abre no modal da página de cima (mdc-photo-viewer).
// O clique é delegado e tratado na fase de captura, antes do `embedtools.js`.
document.addEventListener('click', function (event) {
    const photo = event.target.closest('.mdc-media-view--gallery a');

    // Fora do iframe, o link segue abrindo a foto.
    if (!photo || window.parent === window) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const links = [...photo.closest('.mdc-media-view--gallery').querySelectorAll('a')];

    window.parent.postMessage({
        type: 'mdc.photos.open',
        data: {
            photos: links.map(link => ({ src: link.href, alt: link.querySelector('img')?.alt || '' })),
            index: links.indexOf(photo),
        },
    }, window.location.origin);
}, true);
