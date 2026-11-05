// No subsite dos Mestres, o "Criar Evento" do painel leva direto ao cadastro do festejo em etapas, sem o modal.
(function () {
    const component = app.component('create-event');

    if (!component) {
        return;
    }

    const toTheForm = () => {
        window.location.href = Utils.createUrl('mdc', 'festivity');
    };

    component.template = '<slot :modal="festivityForm"></slot>';
    component.computed = {
        ...(component.computed || {}),
        festivityForm() {
            return { open: toTheForm, close() {} };
        },
    };
})();
