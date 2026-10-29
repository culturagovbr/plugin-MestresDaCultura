<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\UserInterface;

/**
 * Tira do sino e da lista, no subsite dos Mestres, as notificações de outros editais. O edital sai dos links do texto,
 * do pedido vinculado e da entidade citada ("em Oportunidade #5"); sem nenhum deles, a notificação fica.
 */
class NotificationScope
{
    private const REGISTRATION_LINK = '#/(?:inscricao|avaliacao)/(\d+)|/(?:registration|inscricoes)/[A-Za-z]+/(\d+)#';

    private const OPPORTUNITY_LINK = '#/(?:oportunidade|gestao-de-oportunidade|configuracao-de-formulario|lista-de-inscricoes|lista-de-avaliacoes|avaliacoes)/(\d+)|/opportunity/[A-Za-z]+/(\d+)#';

    /** A entidade citada no texto, como "Oportunidade #5" nos alertas de segurança. */
    private const TEXT_REFERENCE = '/\b(Oportunidade|Inscrição|Projeto|Espaço|Evento|Agente) #(\d+)/u';

    private const TEXT_KINDS = ['Oportunidade' => 'opportunities', 'Inscrição' => 'registrations', 'Projeto' => 'projects', 'Espaço' => 'spaces', 'Evento' => 'events', 'Agente' => 'agents'];

    /** Projetos, espaços, eventos e agentes de outro subsite (ver FixedOpportunity::subsiteId) tiram a notificação. */
    private const SUBSITE_TABLES = ['projects' => 'project', 'spaces' => 'space', 'events' => 'event', 'agents' => 'agent'];

    private const CACHE_SECONDS = 86400;

    /**
     * Versão das notificações de cada usuário, no cache comum a todos os subsites (mscache), por um ano. Sem versão
     * guardada, nasce uma nova.
     */
    private const VERSION_KEY = 'mdc:notifications-version:';

    private const VERSION_SECONDS = 365 * 86400;

    /** Apaga a versão das notificações do usuário; a próxima leitura do sino cria outra. */
    static function changed(int $userId): void
    {
        App::i()->mscache->delete(self::VERSION_KEY . $userId);
    }

    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    /**
     * As notificações do usuário que são de outro edital, guardadas no cache até chegar ou sair uma notificação dele
     * (ver changed).
     *
     * @return int[] as notificações do usuário que são de outro edital
     */
    function hiddenFor(UserInterface $user): array
    {
        $app = App::i();
        $version = $app->mscache->fetch(self::VERSION_KEY . $user->id);

        if (!$version) {
            $version = uniqid('', true);
            $app->mscache->save(self::VERSION_KEY . $user->id, $version, self::VERSION_SECONDS);
        }

        // O "v4" é a versão da regra do cálculo.
        $key = "mdc:hidden-notifications:v4:{$user->id}:{$version}";

        if ($app->cache->contains($key)) {
            return $app->cache->fetch($key);
        }

        $hidden = $this->compute($user);
        $app->cache->save($key, $hidden, self::CACHE_SECONDS);

        return $hidden;
    }

    /** @return int[] */
    private function compute(UserInterface $user): array
    {
        $em = App::i()->em;
        $rows = $em->createQuery(
            'SELECT n.id, n.message, r.originType, r.originId, r.destinationType, r.destinationId
               FROM MapasCulturais\Entities\Notification n LEFT JOIN n.request r
              WHERE n.user = :user'
        )->setParameter('user', $user->id)->getArrayResult();

        $references = [];

        foreach ($rows as $row) {
            $ids = self::referencedIds((string) $row['message']);

            foreach ([['originType', 'originId'], ['destinationType', 'destinationId']] as [$type, $id]) {
                $kind = self::kindOf((string) $row[$type]);

                if ($kind && $row[$id]) {
                    $ids[$kind][] = (int) $row[$id];
                }
            }

            $references[$row['id']] = $ids;
        }

        $opportunityOf = $this->opportunitiesOf($references);
        $outsideTheSubsite = $this->outsideTheSubsite($references);
        $allowed = $this->fixedOpportunity->ids();
        $hidden = [];

        foreach ($references as $notification => $ids) {
            foreach (['registrations', 'opportunities', 'evaluations'] as $kind) {
                foreach ($ids[$kind] ?? [] as $id) {
                    $opportunity = $opportunityOf[$kind][$id] ?? null;

                    if ($opportunity && !in_array($opportunity, $allowed, true)) {
                        $hidden[] = $notification;
                        continue 3;
                    }
                }
            }

            foreach (array_keys(self::SUBSITE_TABLES) as $kind) {
                if (array_intersect($ids[$kind] ?? [], $outsideTheSubsite[$kind])) {
                    $hidden[] = $notification;
                    continue 2;
                }
            }
        }

        return $hidden;
    }

    /** @return array{registrations: int[], opportunities: int[], projects: int[], spaces: int[], events: int[], agents: int[]} */
    static function referencedIds(string $message): array
    {
        $found = ['registrations' => [], 'opportunities' => [], 'projects' => [], 'spaces' => [], 'events' => [], 'agents' => []];

        foreach (['registrations' => self::REGISTRATION_LINK, 'opportunities' => self::OPPORTUNITY_LINK] as $kind => $pattern) {
            preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $found[$kind][] = (int) ($match[1] ?: $match[2]);
            }
        }

        preg_match_all(self::TEXT_REFERENCE, $message, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $id]) {
            $found[self::TEXT_KINDS[$name]][] = (int) $id;
        }

        return $found;
    }

    /**
     * Os projetos, espaços, eventos e agentes citados que são de outro subsite; o que não existe mais fica de fora.
     *
     * @return array<string, int[]>
     */
    private function outsideTheSubsite(array $references): array
    {
        $conn = App::i()->conn;
        $subsite = $this->fixedOpportunity->subsiteId();
        $result = [];

        foreach (self::SUBSITE_TABLES as $kind => $table) {
            $wanted = array_values(array_unique(array_merge([], ...array_map(fn ($ids) => $ids[$kind] ?? [], array_values($references)))));
            $result[$kind] = [];

            if ($wanted) {
                $rows = $conn->fetchAllKeyValue("SELECT id, subsite_id FROM {$table} WHERE id IN (" . implode(',', array_map('intval', $wanted)) . ')');

                foreach ($rows as $id => $owner) {
                    if (($owner === null ? null : (int) $owner) !== $subsite) {
                        $result[$kind][] = (int) $id;
                    }
                }
            }
        }

        return $result;
    }

    private static function kindOf(string $class): ?string
    {
        return match (true) {
            $class === '' => null,
            is_a($class, Registration::class, true) => 'registrations',
            is_a($class, Opportunity::class, true) => 'opportunities',
            is_a($class, EvaluationMethodConfiguration::class, true) => 'evaluations',
            default => null,
        };
    }

    /**
     * A oportunidade de cada inscrição, oportunidade e configuração de avaliação citada; o que não existe mais fica de fora.
     *
     * @return array<string, array<int, int>>
     */
    private function opportunitiesOf(array $references): array
    {
        $em = App::i()->em;
        $wanted = ['registrations' => [], 'opportunities' => [], 'evaluations' => []];

        foreach ($references as $ids) {
            foreach ($wanted as $kind => $_) {
                $wanted[$kind] = [...$wanted[$kind], ...($ids[$kind] ?? [])];
            }
        }

        $queries = [
            'registrations' => 'SELECT e.id, IDENTITY(e.opportunity) AS opportunity FROM MapasCulturais\Entities\Registration e WHERE e.id IN (:ids)',
            'opportunities' => 'SELECT e.id, e.id AS opportunity FROM MapasCulturais\Entities\Opportunity e WHERE e.id IN (:ids)',
            'evaluations' => 'SELECT e.id, IDENTITY(e.opportunity) AS opportunity FROM MapasCulturais\Entities\EvaluationMethodConfiguration e WHERE e.id IN (:ids)',
        ];

        $result = [];

        foreach ($queries as $kind => $dql) {
            $result[$kind] = [];

            if ($wanted[$kind]) {
                foreach ($em->createQuery($dql)->setParameter('ids', array_unique($wanted[$kind]))->getArrayResult() as $row) {
                    $result[$kind][(int) $row['id']] = (int) $row['opportunity'];
                }
            }
        }

        return $result;
    }
}
