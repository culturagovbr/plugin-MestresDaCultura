// No subsite dos Mestres, o criar conta aceita a mini bio e a área de atuação vazias; o nome e as demais taxonomias
// obrigatórias seguem a regra do core.
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
