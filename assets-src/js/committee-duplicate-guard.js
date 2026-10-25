// Mostra o erro ao incluir um avaliador na comissão: "já faz parte da comissão" quando a pessoa está na comissão
// recarregada; os outros, com o texto da API.
(function () {
    const component = app.component('opportunity-evaluation-committee');

    if (!component?.methods?.selectAgent) {
        return;
    }

    component.methods.selectAgent = async function (agent) {
        const api = new API();
        const url = Utils.createUrl('evaluationMethodConfiguration', 'createAgentRelation', { id: this.entity.id });

        this.agentData = {
            group: this.group,
            agentId: agent._id,
            has_control: true,
        };

        const res = await api.POST(url, this.agentData);
        const data = await res.json().catch(() => ({}));

        if (!res.ok || data?.error) {
            // Recarrega a comissão e confere nela se a pessoa já faz parte.
            await this.loadReviewers();
            const alreadyIn = (this.allCommitteeRelations || []).some((relation) => relation.group === this.group && relation.agent?.id == agent._id);
            this.messages.error(alreadyIn || typeof data?.data !== 'string' ? this.text('mdcAlreadyInCommittee') : data.data);
            return;
        }

        this.showOwnRegistrationsWarning(data);
        this.loadReviewers();
        this.loadFetchs();
        this.refreshEntityPermissions();
    };
})();
