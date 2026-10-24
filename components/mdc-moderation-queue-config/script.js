// Opções da fila da moderação na configuração da fase (ver Services\ModerationQueue), só com a distribuição automática.
app.component('mdc-moderation-queue-config', {
    template: $TEMPLATES['mdc-moderation-queue-config'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
    },
});
