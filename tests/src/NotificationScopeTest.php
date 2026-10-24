<?php

namespace Tests\MDC;

use MapasCulturais\ApiQuery;
use MapasCulturais\Cache;
use MapasCulturais\Entities\Notification;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MDC\Services\FixedOpportunity;
use MDC\Services\NotificationScope;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\UserDirector;

// No subsite dos Mestres, o sino e a lista de notificações de quem não é admin deixam de fora as de outros editais.
class NotificationScopeTest extends TestCase
{
    use OpportunityBuilder;
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $manager = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($manager);

        return $this->opportunityBuilder
            ->reset(owner: $manager->profile, owner_entity: $manager->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();
    }

    private function registrationOf(User $user, Opportunity $opportunity): Registration
    {
        $this->login($user);

        $registration = new Registration;
        $registration->opportunity = $opportunity;
        $registration->owner = $user->profile;
        $registration->save(true);

        return $registration;
    }

    private function notify(User $user, string $message): int
    {
        $notification = new Notification;
        $notification->user = $user;
        $notification->message = $message;
        $notification->save(true);

        return $notification->id;
    }

    /** @return array{int[], int} o que a lista traz e o que o sino conta */
    private function seenBy(User $user): array
    {
        $this->login($user);
        $ids = array_column((new ApiQuery(Notification::class, ['@select' => 'id', 'user' => "EQ({$user->id})"]))->find(), 'id');
        sort($ids);

        return [$ids, (new ApiQuery(Notification::class, ['user' => "EQ({$user->id})"]))->count()];
    }

    function testNotificationsOfOtherProgramsAreLeftOut()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $master = $this->userDirector->createUser();
        $own = $this->registrationOf($master, $fixed);
        $foreign = $this->registrationOf($master, $other);
        $base = $this->app->baseUrl;

        $program = $this->notify($master, "Seu cadastro foi devolvido. <a href=\"{$base}inscricao/{$own->id}/\">Corrija</a>.");
        $plain = $this->notify($master, 'Sua senha foi alterada.');
        $this->notify($master, "Sua inscrição foi enviada. <a href=\"{$base}inscricao/{$foreign->id}/\">Ver</a>.");
        $this->notify($master, "Você foi convidado a avaliar. <a href=\"http://localhost/lista-de-avaliacoes/{$other->id}/\">Avaliar</a>.");
        $this->notify($master, "Recurso aberto. <a href=\"http://outro.localhost/registration/evaluation/{$foreign->id}/\">Ver</a>.");

        $expected = [$program, $plain];
        sort($expected);

        $this->assertSame([$expected, 2], $this->seenBy($master), 'Lista e sino com as da Rede e as sem link, nada de outros editais');
    }

    // Com o cálculo guardado, a notificação nova de outro edital também fica de fora.
    function testANewNotificationOfAnotherProgramIsLeftOutToo()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $master = $this->userDirector->createUser();
        $foreign = $this->registrationOf($master, $other);
        $plain = $this->notify($master, 'Sua senha foi alterada.');

        // Os testes usam um cache que não guarda nada; aqui, um em memória, para o cálculo ficar guardado de fato.
        $cache = $this->app->cache;
        $this->app->cache = new Cache(new ArrayAdapter());

        try {
            $this->assertSame([[$plain], 1], $this->seenBy($master));

            $this->notify($master, "Sua inscrição foi enviada. <a href=\"{$this->app->baseUrl}inscricao/{$foreign->id}/\">Ver</a>.");

            $this->assertSame([[$plain], 1], $this->seenBy($master), 'A que chegou depois também fica de fora');
        } finally {
            $this->app->cache = $cache;
        }
    }

    function testAdminKeepsEveryNotification()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');
        $this->notify($admin, "Convite. <a href=\"http://localhost/oportunidade/{$other->id}/\">Ver</a>.");

        [$ids, $count] = $this->seenBy($admin);

        $this->assertCount(1, $ids);
        $this->assertSame(1, $count);
    }

    function testFindsTheRegistrationsAndOpportunitiesALinkPointsTo()
    {
        $this->assertSame(
            ['registrations' => [12, 34, 56], 'opportunities' => [78, 90]],
            NotificationScope::referencedIds('<a href="http://x/inscricao/12/">a</a> <a href="https://y/avaliacao/34">b</a> '
                . '<a href="/inscricoes/registrationEdit/56/">c</a> <a href="http://z/oportunidade/78/">d</a> '
                . '<a href="http://z/opportunity/edit/90/#config">e</a> <a href="http://z/agente/11/">f</a>')
        );
    }
}
