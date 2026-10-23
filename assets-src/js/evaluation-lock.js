// Tela de avaliação do programa: Finalizar, Enviar e Reabrir ficam travados enquanto a gravação está em andamento, para
// um clique duplo não mandar a avaliação duas vezes (o servidor também recusa o segundo envio). Voltam quando o
// servidor responde, com ou sem erro, ou depois de 10 segundos; se a tela barrar antes (erro de validação) e nenhuma
// chamada sair, em 1 segundo. Enviar faz duas chamadas seguidas (salvar e enviar): a folga entre elas não destrava.
(() => {
    const BUTTONS = '.evaluation-actions__buttons__final, .evaluation-actions__buttons__finalcontinue, .evaluation-actions__buttons__send, .evaluation-actions__buttons__reopen, .evaluation-actions__buttons__saveafter';

    // "Finalizar e Avançar" só abre o modal com "Enviar agora" e "Enviar depois", que levam a mesma classe.
    const opensTheModal = (button) => button.matches('.evaluation-actions__buttons__finalcontinue') && !button.closest('.modal-content');
    const REQUESTS = /(saveEvaluation|sendEvaluation|reopenEvaluation)/i;
    const LIMIT = 10000;
    const GAP = 400;
    const NO_REQUEST = 1000;

    let pending = 0;
    let started = 0;
    let limit = null;

    const busy = () => document.body.classList.contains('mdc-evaluation-busy');

    const setBusy = (value) => {
        document.body.classList.toggle('mdc-evaluation-busy', value);
        document.querySelectorAll('.evaluation-actions').forEach((actions) => actions.setAttribute('aria-busy', String(value)));
    };

    // O limite conta de novo a cada chamada: salvar e enviar, juntos, podem passar de 10 segundos.
    const restartLimit = () => {
        clearTimeout(limit);
        limit = setTimeout(unlock, LIMIT);
    };

    const unlock = () => {
        clearTimeout(limit);
        limit = null;
        setBusy(false);
    };

    // Na captura, antes do Vue: com a gravação em andamento, o clique (ou Enter) não chega ao botão.
    document.addEventListener('click', (event) => {
        const button = event.target.closest(BUTTONS);

        if (!button || opensTheModal(button)) {
            return;
        }

        if (busy()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        // Na hora: o segundo clique de um clique duplo chega antes de qualquer espera. A classe não impede este clique,
        // que já está a caminho do botão.
        const before = started;
        setBusy(true);
        setTimeout(() => started === before && !pending && unlock(), NO_REQUEST);
        restartLimit();
    }, true);

    const fetch = window.fetch;

    window.fetch = async function (input, ...rest) {
        const url = typeof input === 'string' ? input : (input?.url ?? '');

        if (!REQUESTS.test(url)) {
            return fetch.call(this, input, ...rest);
        }

        pending++;
        started++;

        if (busy()) {
            restartLimit();
        }

        try {
            return await fetch.call(this, input, ...rest);
        } finally {
            pending--;
            setTimeout(() => pending || unlock(), GAP);
        }
    };
})();
