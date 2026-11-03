<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\MetaList;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MDC\Services\FixedOpportunity;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// O campo de links do agente no cadastro do programa mexe só nos links: os vídeos ficam.
class LinksKeepVideosTest extends TestCase
{
    use OpportunityBuilder;
    use RegistrationDirector;
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        return $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('portfolio')
                ->createField('links', 'agent-owner-field', title: 'Links', config: ['entityField' => '@links'])
                ->done()
            ->save()
            ->refresh()
            ->getInstance();
    }

    private function registrationIn(Opportunity $opportunity): Registration
    {
        $registration = $this->registrationDirector->createDraftRegistrations($opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    private function metalist(Agent $agent, string $group, string $url, string $title = ''): MetaList
    {
        $item = new MetaList;
        $item->owner = $agent;
        $item->group = $group;
        $item->title = $title;
        $item->value = $url;
        $item->save(true);

        return $item;
    }

    /** @return array<string, int> endereço => id, do grupo. */
    private function saved(Agent $agent, string $group): array
    {
        $this->app->em->clear();
        $agent = $this->app->repo('Agent')->find($agent->id);
        $items = [];
        foreach ($agent->getMetaLists($group) as $item) {
            $items[$item->value] = $item->id;
        }

        return $items;
    }

    private function saveLinks(Registration $registration, array $links): void
    {
        $field = $this->opportunityBuilder->getFieldName('links', $registration->opportunity);
        $registration->$field = $links;
        $registration->save(true);
    }

    function testSavingTheLinksKeepsTheVideosAndOnlyChangesTheLinks()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $registration = $this->registrationIn($opportunity);
        $agent = $registration->owner;
        $this->metalist($agent, 'videos', 'https://www.youtube.com/watch?v=historia', 'Minha história');
        $this->metalist($agent, 'links', 'https://a.org', 'A');
        $this->metalist($agent, 'links', 'https://b.org', 'B');
        $videos = $this->saved($agent, 'videos');
        $kept = $this->saved($agent, 'links')['https://a.org'];
        $this->assertCount(1, $videos);

        $this->saveLinks($this->app->repo('Registration')->find($registration->id), [
            ['title' => 'A de novo', 'value' => 'https://a.org'],
            ['title' => 'Nova', 'value' => 'https://n.org'],
        ]);

        $this->assertSame($videos, $this->saved($agent, 'videos'), 'O vídeo fica, com o mesmo id');
        $links = $this->saved($agent, 'links');
        $this->assertSame(['https://a.org', 'https://n.org'], array_keys($links), 'O link tirado sai e o novo entra');
        $this->assertSame($kept, $links['https://a.org'], 'O link que ficou não é recriado');
        $this->assertSame('A de novo', $this->app->repo('MetaList')->find($kept)->title);
    }

    function testAVideoAddressInTheLinksBecomesOneVideoAndIsNotDuplicated()
    {
        $opportunity = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $opportunity->id;
        $registration = $this->registrationIn($opportunity);
        $links = [['title' => 'Vídeo', 'value' => 'https://youtu.be/abc'], ['title' => 'Site', 'value' => 'https://site.org']];

        $this->saveLinks($registration, $links);
        $first = $this->saved($registration->owner, 'videos');
        $this->saveLinks($this->app->repo('Registration')->find($registration->id), $links);

        $this->assertSame(['https://youtu.be/abc'], array_keys($first), 'Endereço de vídeo vai para os vídeos, como no core');
        $this->assertSame($first, $this->saved($registration->owner, 'videos'), 'Gravar de novo não duplica nem recria o vídeo');
        $this->assertSame(['https://site.org'], array_keys($this->saved($registration->owner, 'links')));
    }
}
