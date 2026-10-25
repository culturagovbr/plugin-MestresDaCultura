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

// No subsite dos Mestres, o sino e a lista de notificações deixam de fora as de outros editais e outros subsites.
class NotificationScopeTest extends TestCase
{
    use OpportunityBuilder;
    use ProgramSubsite;
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

    // Nada de outros editais no subsite dos Mestres, nem para o gestor.
    function testTheManagerAlsoLosesOtherProgramsNotifications()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        $admin = $this->userDirector->createUser('admin');
        $this->notify($admin, "Convite. <a href=\"http://localhost/oportunidade/{$other->id}/\">Ver</a>.");

        $this->assertSame([[], 0], $this->seenBy($admin));
    }

    // No subsite dos Mestres, ficam de fora os alertas de segurança que citam no texto entidades de outros subsites.
    function testSecurityAlertsOfOtherSubsitesAreLeftOut()
    {
        $fixed = $this->createOpportunity();
        $other = $this->createOpportunity();
        $this->app->config[FixedOpportunity::CONFIG_KEY] = $fixed->id;
        // Grava só a linha do projeto, para o alerta citar.
        $project = (int) $this->app->conn->fetchOne("INSERT INTO project (id, name, create_timestamp, status, type, agent_id) VALUES (nextval('project_id_seq'), 'Fora', now(), 1, 1, :owner) RETURNING id", ['owner' => $this->app->user->profile->id]);
        $local = (int) $this->app->conn->fetchOne("INSERT INTO project (id, name, create_timestamp, status, type, agent_id) VALUES (nextval('project_id_seq'), 'Dos Mestres', now(), 1, 1, :owner) RETURNING id", ['owner' => $this->app->user->profile->id]);
        $subsite = $this->createProgramSubsite($this->app->user->profile->id);
        $this->putInSubsite('opportunity', $fixed->id, $subsite);
        $this->putInSubsite('project', $local, $subsite);
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->app->conn->executeStatement('UPDATE agent SET subsite_id = :subsite WHERE id = :id', ['subsite' => $subsite, 'id' => $admin->profile->id]);
        $alert = fn (string $entity) => $this->notify($admin, "Tentativa de upload de risco bloqueada em {$entity}. Usuário: X. Arquivo: a.php.zip.");

        $alert("Oportunidade #{$other->id}");
        $alert("Projeto #{$project}");
        $alert("Agente #{$other->owner->id}");
        $kept = [$alert("Oportunidade #{$fixed->id}"), $alert("Projeto #{$local}"), $alert("Agente #{$admin->profile->id}")];
        sort($kept);

        $this->assertSame([$kept, 3], $this->seenBy($admin));
    }

    function testFindsTheEntitiesAnAlertNames()
    {
        $this->assertSame(
            ['registrations' => [7], 'opportunities' => [5], 'projects' => [3], 'spaces' => [], 'events' => [9], 'agents' => [11]],
            NotificationScope::referencedIds('Bloqueada em Oportunidade #5. Em Projeto #3, Inscrição #7 e Evento #9. Agente #11.')
        );
    }

    function testFindsTheRegistrationsAndOpportunitiesALinkPointsTo()
    {
        $this->assertSame(
            ['registrations' => [12, 34, 56], 'opportunities' => [78, 90], 'projects' => [], 'spaces' => [], 'events' => [], 'agents' => []],
            NotificationScope::referencedIds('<a href="http://x/inscricao/12/">a</a> <a href="https://y/avaliacao/34">b</a> '
                . '<a href="/inscricoes/registrationEdit/56/">c</a> <a href="http://z/oportunidade/78/">d</a> '
                . '<a href="http://z/opportunity/edit/90/#config">e</a> <a href="http://z/agente/11/">f</a>')
        );
    }
}
