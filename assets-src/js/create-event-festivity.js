// No subsite dos Mestres, o "Criar Evento" do painel leva ao cadastro do festejo em etapas.
(function () {
    const component = app.component('create-event');

    if (!component?.methods?.createEntity) {
        return;
    }

    component.methods.createEntity = function () {
        window.location.href = Utils.createUrl('mdc', 'festivity');
    };
})();
