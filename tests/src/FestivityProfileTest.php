<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MDC\Services\FestivityFields;
use MDC\Services\FestivityProfile;
use MDC\Services\FestivitySeal;
use MDC\Services\ProgramSeal;
use Tests\Abstract\TestCase;
use Tests\Builders\SealBuilder;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A página do festejo traz as seções do programa só com o que é público: nada do CNPJ, do aceite nem dos dados
// pessoais da pessoa responsável.
class FestivityProfileTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    private function profile(Event $event): ?array
    {
        $this->app->em->clear();

        return (new FestivityProfile(new FestivitySeal, new ProgramSeal))->of($this->app->repo('Event')->find($event->id));
    }

    // Uma mestra da Rede (com o selo do programa), ou quem perdeu o selo.
    private function master(string $name, bool $sealed = true): Agent
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $seal = $this->app->repo('Seal')->find((int) ($this->app->config[ProgramSeal::CONFIG_KEY] ?? 0))
            ?? (new SealBuilder)->reset($admin->profile)->fillRequiredProperties()->save()->getInstance();
        $this->app->config[ProgramSeal::CONFIG_KEY] = $seal->id;

        $agent = $this->userDirector->createUser()->profile;
        $this->app->disableAccessControl();
        $agent->name = $name;
        $agent->save(true);
        if ($sealed) {
            $agent->createSealRelation($seal, true, true);
        }
        $this->app->enableAccessControl();

        return $agent;
    }

    function testTheProfileCarriesOnlyPublicData()
    {
        $event = $this->draft();
        $this->complete($event, withPeriod: false);
        $owner = $event->owner;
        $owner->nomeCompleto = 'Celina Pereira dos Santos';
        $owner->emailPrivado = 'celina.privado@example.com';
        $owner->emailPublico = 'samba.santabarbara@example.com';
        $owner->save(true);
        $event->{FestivityFields::PRODUCER} = 'Pessoa Jurídica sem fins lucrativos';
        $event->{FestivityFields::INSTITUTION} = 'Associação Filhas do Recôncavo';
        $event->{FestivityFields::CNPJ} = '11.222.333/0001-81';
        $event->{FestivityFields::SIZE} = 'Médio';
        $event->{FestivityFields::ECONOMY} = ['Música: DJ'];
        $event->mdcFestejoRouanet = 'Sim';
        $event->mdcFestejoRouanetAnos = '2023, 2024';
        $event->{FestivityFields::CONSENT} = '1';
        $event->save(true);
        $consent = $event->{FestivityFields::CONSENT};
        $this->period($event, $this->place($event));
        $this->app->em->refresh($event);
        $event->publish(true);
        $this->logout();

        $profile = $this->profile($event);
        $json = json_encode($profile, JSON_UNESCAPED_UNICODE);

        foreach (['11.222.333/0001-81', 'celina.privado@example.com', 'Celina Pereira dos Santos', $consent] as $private) {
            $this->assertStringNotContainsString($private, $json, "{$private} não vai para a página");
        }
        $this->assertSame('samba.santabarbara@example.com', $profile['email'], 'O e-mail autorizado vai');
        $this->assertSame('Associação Filhas do Recôncavo', $profile['institution']);
        $this->assertSame('Médio: de 501 a 5.000 pessoas', $profile['size']);
        $this->assertSame([['segment' => 'Música', 'items' => ['DJ']]], $profile['economy']);
        $this->assertSame([['label' => 'Lei Rouanet', 'years' => '2023, 2024']], $profile['funding']);
        $this->assertNotEmpty($profile['certificateUrl'], 'Publicado, tem o certificado');
    }

    function testOnlyMastersOfTheNetworkAppear()
    {
        $master = $this->master('Mestra Celina do Samba');
        $former = $this->master('Quem perdeu o selo', false);
        $event = $this->draft();
        // Grava direto uma mestra da Rede e uma que perdeu o selo.
        $event->{FestivityFields::MASTERS} = "|{$master->id}|{$former->id}|";
        $event->save(true);

        $this->assertSame([$master->id], array_column($this->profile($event)['masters'], 'id'));
    }

    function testTheEventPageShowsTheFestivity()
    {
        $event = $this->draft();
        $this->complete($event);
        $event->publish(true);
        $this->logout();

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('event', 'single', [$event->id]), false);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $this->assertStringContainsString('<mdc-festivity-profile', (string) $this->app->response->getBody());
    }
}
