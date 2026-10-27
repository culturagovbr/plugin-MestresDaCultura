<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Services\ActingField;
use MDC\Services\FixedOpportunity;
use MDC\Services\MasterPublicData;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Na aprovação, o agente da mestra ganha os dados públicos do programa (nome artístico, UF, cidade, atuação), lidos
// pela API do core; ao deixar de estar aprovado, perde.
class MasterPublicDataTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use RegistrationDirector;
    use RequestFactory;
    use UserDirector;

    private Opportunity $opportunity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureLog();

        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        $this->opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('etapa')
                ->createField('atuacao', ActingField::SLUG, title: 'Atuação Cultural')
                ->createField('cidade', 'municipio', title: 'Qual Cidade você vive?')
                ->done()
            ->save()
            ->refresh()
            ->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunity->id;

        $seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;
    }

    // Enviada, com a atuação (Carnaval e Folia de Reis, em Celebrações e Rituais) e a cidade.
    private function registration(): Registration
    {
        $registration = $this->registrationDirector->createDraftRegistrations($this->opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        $nodes = [];
        foreach (['Carnaval', 'Folia de Reis'] as $name) {
            $node = $this->app->conn->fetchAssociative('SELECT id, parent_id FROM mdc_tree_node WHERE name = :name ORDER BY level LIMIT 1', ['name' => $name]);
            $nodes[$node['parent_id']] = ['id' => (int) $node['parent_id'], 'parent' => null, 'name' => 'Celebrações e Rituais'];
            $nodes[$node['id']] = ['id' => (int) $node['id'], 'parent' => (int) $node['parent_id'], 'name' => $name];
        }

        $this->app->disableAccessControl();
        $registration->owner->nomeSocial = 'Mestra Maria';
        $registration->owner->save(true);
        $registration->{$this->opportunityBuilder->getFieldName('atuacao')} = ['nodes' => array_values($nodes), 'others' => []];
        $registration->{$this->opportunityBuilder->getFieldName('cidade')} = 'Olinda, PE';
        $registration->status = Registration::STATUS_SENT;
        $registration->save(true);
        $this->app->enableAccessControl();

        return $registration;
    }

    private function agent(int $id): Agent
    {
        $this->app->em->clear();

        return $this->app->repo('Agent')->find($id);
    }

    private function setStatus(Registration $registration, string $method): void
    {
        $this->app->disableAccessControl();
        $this->app->repo('Registration')->find($registration->id)->$method();
        $this->app->enableAccessControl();
    }

    function testTheApprovalPublishesTheProgramData()
    {
        $registration = $this->registration();
        $agentId = $registration->owner->id;

        $this->setStatus($registration, 'setStatusToApproved');
        $agent = $this->agent($agentId);

        $this->assertSame('Mestra Maria', $agent->{MasterPublicData::KNOWN_AS});
        $this->assertSame('PE', $agent->{MasterPublicData::UF});
        $this->assertSame('Olinda, PE', $agent->{MasterPublicData::CITY});
        $this->assertSame('Carnaval, Folia de Reis', $agent->{MasterPublicData::ACTIVITIES});
        $carnaval = (int) $this->app->conn->fetchOne("SELECT id FROM mdc_tree_node WHERE name = 'Carnaval' ORDER BY level LIMIT 1");
        $this->assertStringContainsString("|{$carnaval}|", (string) $agent->{MasterPublicData::ACTING}, 'Os itens da atuação, para o filtro');
    }

    function testAVisitorReadsItThroughTheApi()
    {
        $registration = $this->registration();
        $agentId = $registration->owner->id;
        $this->setStatus($registration, 'setStatusToApproved');
        $this->app->auth->logout();

        $found = (new ApiQuery(Agent::class, ['@select' => 'id,' . MasterPublicData::UF, MasterPublicData::UF => 'EQ(PE)', 'id' => "EQ({$agentId})"]))->find();

        $this->assertSame('PE', $found[0][MasterPublicData::UF] ?? null, 'A busca filtra e lê a UF como visitante');
    }

    function testLeavingTheApprovalClearsIt()
    {
        $registration = $this->registration();
        $agentId = $registration->owner->id;
        $this->setStatus($registration, 'setStatusToApproved');

        $this->setStatus($registration, 'setStatusToNotApproved');
        $agent = $this->agent($agentId);

        foreach ([MasterPublicData::KNOWN_AS, MasterPublicData::UF, MasterPublicData::CITY, MasterPublicData::ACTIVITIES, MasterPublicData::ACTING] as $key) {
            $this->assertEmpty($agent->$key, "{$key} sai com a aprovação");
        }
    }

    // A busca por nome da página de Mestras e Mestres procura também o nome artístico.
    function testTheNameSearchAlsoFindsTheArtisticName()
    {
        $registration = $this->registration();
        $agentId = $registration->owner->id;
        $this->setStatus($registration, 'setStatusToApproved');
        $this->app->auth->logout();

        $found = (new ApiQuery(Agent::class, ['@select' => 'id', '@keyword' => '%mestra maria%', 'id' => "EQ({$agentId})"]))->find();

        $this->assertSame([$agentId], array_column($found, 'id'));
    }

    function testTheSearchPageOpensForVisitors()
    {
        $this->app->auth->logout();
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'masters'), false);
        $body = (string) $this->app->response->getBody();

        $this->assertSame(200, $this->app->response->getStatusCode());
        $this->assertStringContainsString('<mdc-masters-search', $body);
    }
}
