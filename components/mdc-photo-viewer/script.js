// Mesmo nome que a ficha do v1 manda, em assets-src/js/plugin-MDC.js.
const MDC_PHOTOS_MESSAGE = 'mdc.photos.open';

app.component('mdc-photo-viewer', {
    template: $TEMPLATES['mdc-photo-viewer'],

    setup() {
        const text = Utils.getTexts('mdc-photo-viewer');

        // substitui cada `%s` do texto pelo próximo argumento
        const format = (key, ...values) => values.reduce((s, v) => s.replace('%s', v), text(key));

        return { text, format };
    },

    data() {
        return {
            photos: [],
            index: 0,
        };
    },

    computed: {
        current() {
            return this.photos[this.index];
        },

        title() {
            return this.photos.length > 1 ? this.format('contador', this.index + 1, this.photos.length) : this.text('foto');
        },
    },

    created() {
        window.addEventListener('message', this.receive);
        window.addEventListener('keydown', this.navigate);
    },

    unmounted() {
        window.removeEventListener('message', this.receive);
        window.removeEventListener('keydown', this.navigate);
    },

    methods: {
        // Só aceita a ficha servida pelo próprio site, e só endereços http(s).
        receive(event) {
            if (event.origin !== window.location.origin || event.data?.type !== MDC_PHOTOS_MESSAGE) {
                return;
            }

            const { photos, index } = event.data.data;

            if (!photos?.length || !photos.every(photo => /^https?:\/\//i.test(photo.src)) || !photos[index]) {
                return;
            }

            this.photos = photos;
            this.index = index;
            this.$refs.modal.open();
        },

        // As setas do teclado trocam a foto com o modal aberto.
        navigate(event) {
            if (!this.$refs.modal?.modalOpen) {
                return;
            }

            if (event.key === 'ArrowLeft') {
                this.previous();
            } else if (event.key === 'ArrowRight') {
                this.next();
            }
        },

        previous() {
            this.index = (this.index - 1 + this.photos.length) % this.photos.length;
        },

        next() {
            this.index = (this.index + 1) % this.photos.length;
        },
    },
});
