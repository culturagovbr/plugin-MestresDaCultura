// No subsite dos Mestres, a mini bio e a área de atuação não são obrigatórias (ver Plugin::skipProfileFieldsTheProgramDoesNotAsk,
// como na Pnab), mas o componente de criar conta (MultipleLocalAuth) as exige no próprio método validateAgent, sem ler
// a regra do agente. Envolve o método, como o Cultura Viva envolve os do componente dele: os dois, vazios, entram só
// durante a checagem; o nome e as demais taxonomias obrigatórias seguem pela regra do core.
(function () {
    const component = app.component('create-account');
    const validateAgent = component?.methods?.validateAgent;

    if (!validateAgent) {
        return;
    }

    component.methods.validateAgent = function () {
        const shortDescription = this.agent.shortDescription;
        const area = this.agent.terms.area;

        if (!shortDescription) {
            this.agent.shortDescription = '-';
        }
        if (!area?.length) {
            this.agent.terms.area = ['-'];
        }

        try {
            return validateAgent.call(this);
        } finally {
            this.agent.shortDescription = shortDescription;
            this.agent.terms.area = area;
        }
    };
})();
