<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Seal;
use MDC\Services\ActingField;
use MDC\Services\FixedOpportunity;
use MDC\Services\MasterProfile;
use MDC\Services\MasterPublicData;
use MDC\Services\MasterShowcase;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Builders\SealBuilder;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A vitrine da home mostra as mestras e os mestres com o selo do programa, os aprovados mais recentes primeiro,
// só com dados públicos, e conta quantos são e de quantas unidades da federação.
class MasterShowcaseTest extends TestCase
{
    use CapturesLog;
    use OpportunityBuilder;
    use RegistrationDirector;
    use RequestFactory;
    use UserDirector;

    private Opportunity $opportunity;
    private Seal $seal;

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

        $this->seal = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $this->seal->id;
    }

    /** Mestra aprovada, com o selo dado há `$daysAgo` dias. */
    private function master(string $name, string $knownAs, string $city, array $activities, int $daysAgo, ?Seal $seal = null): Agent
    {
        $registration = $this->registrationDirector->createDraftRegistrations($this->opportunity, 1)[0];
        $agent = $registration->owner;
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        $nodes = $this->nodes($activities);
        $this->app->disableAccessControl();
        $agent->name = $name;
        // O nome artístico público vem da cópia da aprovação (MasterPublicData), não do `nomeSocial` do core.
        $agent->nomeSocial = 'Nome social privado';
        $agent->{MasterPublicData::KNOWN_AS} = $knownAs;
        $agent->emailPrivado = 'privado@example.com';
        $agent->longDescription = "Aprendeu com a avó.\n\n" . str_repeat('Ensina na comunidade. ', 20);
        $agent->save(true);
        $registration->{$this->opportunityBuilder->getFieldName('atuacao')} = ['nodes' => $nodes, 'others' => []];
        $registration->{$this->opportunityBuilder->getFieldName('cidade')} = $city;
        $registration->save(true);
        $agent->createSealRelation($seal ?? $this->seal, true, true);
        $this->app->enableAccessControl();

        // Aprovada e com o selo de `$daysAgo` dias, sem passar pelos ganchos da aprovação.
        $this->app->conn->executeStatement('UPDATE registration SET status = 10 WHERE id = :id', ['id' => $registration->id]);
        $this->app->conn->executeStatement("UPDATE seal_relation SET create_timestamp = now() - make_interval(days => :days) WHERE object_id = :agent AND seal_id = :seal",
            ['days' => $daysAgo, 'agent' => $agent->id, 'seal' => ($seal ?? $this->seal)->id]);

        return $agent;
    }

    /** Os itens da árvore pelo nome, cada um depois do pai, como a resposta do campo de atuação. */
    private function nodes(array $names): array
    {
        $nodes = [];
        foreach ($names as $name) {
            $node = $this->app->conn->fetchAssociative('SELECT id, parent_id, name FROM mdc_tree_node WHERE name = :name ORDER BY level LIMIT 1', ['name' => $name]);
            if ($node['parent_id'] && !isset($nodes[$node['parent_id']])) {
                $parent = $this->app->conn->fetchAssociative('SELECT id, parent_id, name FROM mdc_tree_node WHERE id = :id', ['id' => $node['parent_id']]);
                $nodes[$parent['id']] = ['id' => (int) $parent['id'], 'parent' => null, 'name' => $parent['name']];
            }
            $nodes[$node['id']] = ['id' => (int) $node['id'], 'parent' => $node['parent_id'] ? (int) $node['parent_id'] : null, 'name' => $node['name']];
        }

        return array_values($nodes);
    }

    function testShowsTheMostRecentlyApprovedMastersWithPublicDataOnly()
    {
        $older = $this->master('Maria da Silva', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 10);
        $newer = $this->master('João Santos', 'Mestre João', 'Juazeiro do Norte, CE', ['Carnaval', 'Folia de Reis'], 2);

        $highlights = (new MasterShowcase)->highlights();

        $this->assertSame([$newer->id, $older->id], array_column($highlights, 'id'), 'Os aprovados mais recentes primeiro');
        $this->assertSame('Mestre João', $highlights[0]['knownAs']);
        $this->assertSame(['Carnaval', 'Folia de Reis'], $highlights[0]['activities'], 'As atividades marcadas, sem o segmento acima delas');
        $this->assertSame('Juazeiro do Norte, CE', $highlights[0]['city']);
        $this->assertSame($newer->singleUrl, $highlights[0]['url']);
        $this->assertStringStartsWith('Aprendeu com a avó. Ensina', $highlights[0]['summary'], 'Sem mini bio, o começo da descrição longa');
        $this->assertLessThanOrEqual(160, mb_strlen($highlights[0]['summary']));
        $this->assertSame(['id', 'name', 'knownAs', 'activities', 'city', 'summary', 'avatar', 'url'], array_keys($highlights[0]), 'Só dados públicos');
        $this->assertStringNotContainsString('privado@example.com', json_encode($highlights));
        $this->assertStringNotContainsString('Nome social privado', json_encode($highlights), 'O nome social do core é privado');

        $profile = (new MasterProfile(new FixedOpportunity, new ProgramSeal))->of($newer);
        $this->assertSame('Mestre João', $profile['knownAs'], 'O perfil público também usa a cópia moderada');

        $this->assertCount(1, (new MasterShowcase)->highlights(1), 'No máximo o pedido');
    }

    function testCountsTheMastersAndTheirStates()
    {
        $this->master('Maria', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 3);
        $this->master('Ana', 'Mestra Ana', 'Recife, PE', ['Artesanato'], 2);
        $this->master('João', 'Mestre João', 'Juazeiro do Norte, CE', ['Carnaval'], 1);

        $this->assertSame(['masters' => 3, 'states' => 2], (new MasterShowcase)->counts());
    }

    function testLeavesOutWhoDoesNotHaveTheProgramSeal()
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);
        $other = (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->master('Fora', 'Fora da Rede', 'Natal, RN', ['Artífice'], 1, $other);

        $this->assertSame([], (new MasterShowcase)->highlights());
        $this->assertSame(['masters' => 0, 'states' => 0], (new MasterShowcase)->counts());
    }

    // O mapa da Rede conta por UF, na mesma base dos contadores.
    function testCountsTheMastersOfEachState()
    {
        $this->master('Maria', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 3);
        $this->master('Ana', 'Mestra Ana', 'Recife, PE', ['Artesanato'], 2);
        $this->master('João', 'Mestre João', 'Juazeiro do Norte, CE', ['Carnaval'], 1);

        $this->assertSame(['CE' => 1, 'PE' => 2], (new MasterShowcase)->byState());
    }

    // Com foto de perfil, o cartão leva a versão reduzida da foto.
    function testTheCardCarriesTheProfilePhoto()
    {
        $master = $this->master('Maria', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 1);
        $tmp = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
        imagejpeg(imagecreatetruecolor(300, 300), $tmp);

        $this->app->disableAccessControl();
        $photo = new \MapasCulturais\Entities\AgentFile(['error' => UPLOAD_ERR_OK, 'name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
        $photo->group = 'avatar';
        $photo->owner = $master;
        $photo->save(true);
        $this->app->enableAccessControl();

        $avatar = (new MasterShowcase)->highlights()[0]['avatar'];

        $this->assertNotNull($avatar);
        $this->assertStringContainsString("/files/agent/{$master->id}/", $avatar);
    }

    // A home leva os dados da vitrine e o desenho dos estados do mapa da Rede (data/br-uf.json) no objeto da página.
    // O título da página do perfil (aba e prévia ao compartilhar) usa o nome artístico da mestra aprovada.
    function testTheProfileTitleUsesTheKnownAsName()
    {
        $known = $this->master('Maria da Silva', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 1);
        $unknown = $this->master('José dos Santos', '', 'Recife, PE', ['Artífice'], 1);
        $this->login($this->userDirector->createUser());

        $title = function (Agent $agent): string {
            $this->app->reset();
            $this->app->run($this->requestFactory->GET('agent', 'single', [$agent->id]), false);
            preg_match('#<title>(.*?)</title>#s', (string) $this->app->response->getBody(), $match);

            return trim(html_entity_decode($match[1] ?? ''));
        };

        $this->assertStringStartsWith('Mestra Maria - ', $title($known));
        $this->assertStringStartsWith('José dos Santos - ', $title($unknown), 'Sem nome artístico, fica o nome do perfil');
    }

    function testTheHomeCarriesTheShowcaseAndTheMap()
    {
        $this->master('Maria da Silva', 'Mestra Maria', 'Olinda, PE', ['Artífice'], 1);
        // Abre a home logado.
        $this->login($this->userDirector->createUser());

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('site', 'index'), false);
        $showcase = $this->app->view->jsObject['config']['mdcShowcase'] ?? null;

        $this->assertSame(200, $this->app->response->getStatusCode());
        $this->assertSame('Mestra Maria', $showcase['highlights'][0]['knownAs'] ?? null);
        $this->assertNotEmpty($showcase['map'] ?? null, 'O desenho dos estados chega ao mapa');
    }
}
