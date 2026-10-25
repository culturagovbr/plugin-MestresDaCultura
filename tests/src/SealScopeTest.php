<?php

namespace Tests\MDC;

use Laminas\Diactoros\ServerRequest;
use MapasCulturais\API;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// No subsite dos Mestres, quem não é admin só vê o selo do programa: na API, no perfil e nas páginas de selo.
class SealScopeTest extends TestCase
{
    use OpportunityBuilder;
    use ProgramSubsite;
    use RequestFactory;
    use UserDirector;

    private Seal $programSeal;

    private Seal $otherSeal;

    private Agent $master;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        $this->programSeal = (new SealBuilder)->reset($manager->profile)->fillRequiredProperties()->save()->getInstance();
        $this->otherSeal = (new SealBuilder)->reset($manager->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $this->programSeal->id;

        $this->master = $this->userDirector->createUser()->profile;
        $this->login($manager);
        $this->master->createSealRelation($this->programSeal, true, true);
        $this->master->createSealRelation($this->otherSeal, true, true);
    }

    /** @return int[] */
    private function sealIds(User $user): array
    {
        $this->login($user);
        $ids = array_column((new ApiQuery(Seal::class, ['@select' => 'id']))->find(), 'id');
        sort($ids);

        return $ids;
    }

    /** @return int[] os selos do mestre que a API de agentes devolve */
    private function agentApiSeals(User $user, string $action): array
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run(new ServerRequest(method: 'GET', uri: "/api/agent/{$action}", queryParams: ['@select' => 'id,seals', 'id' => API::EQ($this->master->id)]), false);
        $result = json_decode((string) $this->app->response->getBody(), true);
        $agent = $action === 'findOne' ? $result : ($result[0] ?? []);

        return array_column($agent['seals'] ?? [], 'sealId');
    }

    private function statusOf(User $user, string $action, int $id): int
    {
        $this->login($user);
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('seal', $action, [$id]), false);

        return $this->app->response->getStatusCode();
    }

    private function relationOf(Seal $seal): int
    {
        return $this->app->repo('AgentSealRelation')->findOneBy(['seal' => $seal, 'owner' => $this->master])->id;
    }

    function testTheApiBringsOnlyTheProgramSealToWhoIsNotAdmin()
    {
        $this->assertSame([$this->programSeal->id], $this->sealIds($this->userDirector->createUser()), 'Só o selo do programa');
    }

    // O gestor e o saasSuperAdmin veem só os selos do subsite dos Mestres: na Gestão de selos, nos selos do agente e nas
    // páginas de selo.
    function testAdminsSeeOnlyTheProgramSubsiteSeals()
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);
        $fixed = $this->opportunityBuilder->reset(owner: $manager->profile, owner_entity: $manager->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $localSeal = (new SealBuilder)->reset($manager->profile)->fillRequiredProperties()->save()->getInstance();
        $subsite = $this->createProgramSubsite($manager->profile->id);
        $this->putInSubsite('opportunity', $fixed->id, $subsite);
        $this->putInSubsite('seal', $this->programSeal->id, $subsite);
        $this->putInSubsite('seal', $localSeal->id, $subsite);
        $expected = [$this->programSeal->id, $localSeal->id];
        sort($expected);

        foreach (['admin', 'saasSuperAdmin'] as $role) {
            $user = $this->userDirector->createUser($role);

            $this->assertSame($expected, $this->sealIds($user), "{$role}: só os selos do subsite dos Mestres");
            $this->assertSame([$this->programSeal->id], $this->agentApiSeals($user, 'find'), "{$role}: o agente traz só os selos do subsite");
            $this->assertEquals(404, $this->statusOf($user, 'single', $this->otherSeal->id), "{$role}: a página de selo de fora não abre");
            $this->assertNotEquals(404, $this->statusOf($user, 'single', $localSeal->id), "{$role}: a de um selo do subsite abre");
        }
    }

    function testTheAgentApiBringsOnlyTheProgramSeal()
    {
        $visitor = $this->userDirector->createUser();

        foreach (['find', 'findOne'] as $action) {
            $this->assertSame([$this->programSeal->id], $this->agentApiSeals($visitor, $action), "api/agent/{$action} traz só o selo do programa");
        }
    }

    // O JSON do agente na página do perfil leva só o selo do programa.
    function testTheProfilePageCarriesOnlyTheProgramSeal()
    {
        // Configura a oportunidade do programa, que a página do perfil procura.
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance()->id;

        $this->login($this->userDirector->createUser());
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('agent', 'single', [$this->master->id]), false);
        $agent = $this->app->view->jsObject['requestedEntity'] ?? [];

        $this->assertSame([$this->programSeal->id], array_map('intval', array_column($agent['seals'] ?? [], 'sealId')), 'O JSON do perfil traz só o selo do programa');
    }

    function testPagesOfOtherSealsAreNotFound()
    {
        $visitor = $this->userDirector->createUser();

        $this->assertEquals(404, $this->statusOf($visitor, 'single', $this->otherSeal->id), 'A página de outro selo não abre');
        $this->assertEquals(404, $this->statusOf($visitor, 'sealRelation', $this->relationOf($this->otherSeal)), 'Nem o certificado dele');
        $this->assertNotEquals(404, $this->statusOf($visitor, 'sealRelation', $this->relationOf($this->programSeal)), 'O certificado do programa abre');
    }
}
