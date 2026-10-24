// Como no Cultura Viva (themes/CulturaViva/assets/js/evaluation-submit-guard.js): uma ação de concluir, enviar ou
// reabrir por vez na avaliação, enquanto a anterior não termina; o servidor também recusa o segundo envio (ver
// Services\EvaluationSendLock). A classe esmaece e bloqueia os botões (tema, _evaluation-actions.scss).
(function () {
    const component = app.component('evaluation-actions');

    if (!component?.methods) {
        return;
    }

    let busy = false;
    let release = null;

    // O elemento raiz do componente: o `$el` ou, quando ele é nó de texto, o elemento seguinte.
    const rootOf = (vm) => vm.$el?.nodeType === Node.ELEMENT_NODE ? vm.$el : vm.$el?.nextElementSibling;

    function guard(original) {
        return function (...args) {
            if (busy) {
                return;
            }

            busy = true;
            const root = rootOf(this);
            root?.classList.add('evaluation-actions--busy');

            const finish = () => {
                clearTimeout(release);
                busy = false;
                root?.classList.remove('evaluation-actions--busy');
            };

            clearTimeout(release);
            // Libera os botões depois de 10 segundos sem resposta.
            release = setTimeout(finish, 10000);

            let result;
            try {
                result = original.apply(this, args);
            } catch (error) {
                finish();
                throw error;
            }

            // Com erro de validação, nada é enviado: libera os botões na hora.
            if (this.globalState?.validateEvaluationErrors) {
                finish();
                return result;
            }

            Promise.resolve(result).then(finish, finish);
            return result;
        };
    }

    component.methods.finishEvaluation = guard(component.methods.finishEvaluation);
    component.methods.finishEvaluationSend = guard(component.methods.finishEvaluationSend);
    component.methods.finishEvaluationSendLater = guard(component.methods.finishEvaluationSendLater);
    component.methods.reopen = guard(component.methods.reopen);
})();
