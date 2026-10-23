<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\UserInterface;

/**
 * No subsite dos Mestres, as notificações de outros editais ficam fora do sino e da lista. O core e os plugins gravam a
 * notificação como texto, quase sempre sem pedido vinculado; o edital sai dos links do texto (o número da inscrição
 * ou da oportunidade, não o domínio) e, quando há, do pedido. Sem link nem pedido, não há como saber: fica.
 */
class NotificationScope
{
    private const REGISTRATION_LINK = '#/(?:inscricao|avaliacao)/(\d+)|/(?:registration|inscricoes)/[A-Za-z]+/(\d+)#';

    private const OPPORTUNITY_LINK = '#/(?:oportunidade|gestao-de-oportunidade|configuracao-de-formulario|lista-de-inscricoes|lista-de-avaliacoes|avaliacoes)/(\d+)|/opportunity/[A-Za-z]+/(\d+)#';

    function __construct(private FixedOpportunity $fixedOpportunity)
    {
    }

    /** @return int[] as notificações do usuário que são de outro edital */
    function hiddenFor(UserInterface $user): array
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
        }

        return $hidden;
    }

    /** @return array{registrations: int[], opportunities: int[]} */
    static function referencedIds(string $message): array
    {
        $found = ['registrations' => [], 'opportunities' => []];

        foreach (['registrations' => self::REGISTRATION_LINK, 'opportunities' => self::OPPORTUNITY_LINK] as $kind => $pattern) {
            preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $found[$kind][] = (int) ($match[1] ?: $match[2]);
            }
        }

        return $found;
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
