// No subsite dos Mestres, os links das entidades na tela: o nome cuja página não abre para quem vê fica sem link, e a
// edição do festejo leva ao formulário em etapas.
(function () {
    const closed = $MAPAS.config.mdcClosedPages;
    const component = app.component('mc-link');

    if (!closed || typeof Entity === 'undefined') {
        return;
    }

    const isClosed = (entity) => !!entity && (closed.types.includes(entity.__objectType)
        || (entity.__objectType === 'agent' && closed.agents.includes(entity.id)));

    const getUrl = Entity.prototype.getUrl;
    Entity.prototype.getUrl = function (action, params) {
        if (action === 'single' && isClosed(this)) {
            return null;
        }

        if (action === 'edit' && this.__objectType === 'event' && this.id) {
            return Utils.createUrl('mdc', 'editFestivity', [this.id]);
        }

        return getUrl.call(this, action, params);
    };

    if (component?.computed?.url) {
        const url = component.computed.url;
        component.computed.url = function () {
            return (!this.route || this.route === 'single') && isClosed(this.entity) ? null : url.call(this);
        };
    }
})();
