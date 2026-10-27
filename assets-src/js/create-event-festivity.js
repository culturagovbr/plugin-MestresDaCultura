// No subsite dos Mestres todo evento é festejo, cadastrado em 6 etapas (ver Plugin::openTheFestivityForm). O "Criar
// Evento" do painel abre o modal do core (create-event), que grava o evento direto, sem as etapas; aqui ele leva ao
// cadastro do festejo. Envolve o método, como create-account-optional.js: o modal só desenha o formulário com a entidade.
(function () {
    const component = app.component('create-event');

    if (!component?.methods?.createEntity) {
        return;
    }

    component.methods.createEntity = function () {
        window.location.href = Utils.createUrl('mdc', 'festivity');
    };
})();
