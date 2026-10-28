<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Space;

/**
 * O local do festejo é um Espaço do core, que cada ocorrência da agenda pede. No subsite dos Mestres, os espaços
 * seguem sem busca, painel nem página própria; quem tem conta grava só o próprio local, pelas rotas do formulário.
 */
class FestivityPlace
{
    // O tipo do local; o padrão é "Outros (informar qual)", dos tipos do SNIIC (plugin SNIICDataStandard).
    const CONFIG_KEY = 'mdc.festivityPlaceType';

    const TYPE = 2040;

    // O "Outros (informar qual)" do SNIIC pede o nome do tipo, neste campo; ele só existe com o SNIIC.
    const OTHER_TYPE_FIELD = 'informarQualOutroTipoDeEspaco';

    // [método, ação] de espaço que ficam abertos. A exclusão é a de "Meus locais", barrada com festejo no local.
    const WRITES = [['POST', 'index'], ['PATCH', 'single'], ['PUT', 'single'], ['DELETE', 'single']];

    /** @var array<int, int> os locais de períodos apagados, para conferir na gravação do festejo que vem em seguida */
    private static array $released = [];

    /** Ligado enquanto syncStatus grava a situação do local. */
    static bool $syncing = false;

    static function type(): int
    {
        return (int) (App::i()->config[self::CONFIG_KEY] ?? self::TYPE);
    }

    static function isWrite(string $method, string $action): bool
    {
        return in_array([strtoupper($method), $action], self::WRITES, true);
    }

    /**
     * A UF, a cidade, a região e o resumo da agenda vêm do primeiro período, pela data (itens 10 a 13; no festejo
     * itinerante, o primeiro). O core salva o evento a cada período gravado ou apagado (EventOccurrence::save e
     * delete); o plugin chama isto antes de salvar o festejo e ao mudar o endereço de um local.
     */
    static function sync(Event $event): void
    {
        if (!$event->id) {
            return;
        }

        $first = App::i()->em->createQuery('SELECT o FROM ' . EventOccurrence::class . ' o WHERE o.event = :event AND o.status > 0 ORDER BY o.startsOn ASC, o.id ASC')
            ->setParameter('event', $event)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        $uf = (string) $first?->space?->En_Estado;
        $values = [
            FestivityFields::UF => $uf,
            FestivityFields::CITY => (string) $first?->space?->En_Municipio,
            FestivityFields::REGION => FestivityFields::region($uf),
            // Para os cartões da busca: o texto do período, ou a data dele.
            FestivityFields::AGENDA_SUMMARY => $first ? ((string) $first->description ?: $first->startsOn?->format('d/m/Y') ?? '') : '',
        ];

        foreach ($values as $key => $value) {
            if ((string) $event->$key !== $value) {
                $event->$key = $value;
            }
        }
    }

    /** @return list<Event> os eventos com algum período no local; com `$status`, só os nessa situação */
    static function eventsAt(int $spaceId, ?int $status = null): array
    {
        // Só os períodos valendo; o pendente não conta.
        $dql = 'SELECT e FROM ' . Event::class . ' e WHERE e.id IN (SELECT IDENTITY(o.event) FROM ' . EventOccurrence::class . ' o WHERE IDENTITY(o.space) = :space AND o.status > 0)';
        $query = App::i()->em->createQuery($dql . ($status === null ? '' : ' AND e.status = :status'))->setParameter('space', $spaceId);

        return $status === null ? $query->getResult() : $query->setParameter('status', $status)->getResult();
    }

    /** Guarda o local do período apagado para a próxima gravação do festejo. */
    static function release(EventOccurrence $occurrence): void
    {
        if ($id = $occurrence->space?->id) {
            self::$released[$id] = $id;
        }
    }

    /**
     * O local segue o festejo: publicado se algum festejo publicado tem período nele, em rascunho se não. Na lixeira
     * ou arquivado, fica como está.
     */
    static function syncStatus(Event $event): void
    {
        $app = App::i();
        $ids = array_column($app->em->createQuery('SELECT DISTINCT IDENTITY(o.space) AS id FROM ' . EventOccurrence::class . ' o WHERE o.event = :event')
            ->setParameter('event', $event)
            ->getScalarResult(), 'id');
        $ids = array_unique(array_merge(array_map('intval', $ids), array_values(self::$released)));
        self::$released = [];

        foreach ($ids as $id) {
            $space = $app->repo('Space')->find($id);

            if (!$space || $space->status < Space::STATUS_DRAFT) {
                continue;
            }

            $status = self::eventsAt($id, Event::STATUS_ENABLED) ? Space::STATUS_ENABLED : Space::STATUS_DRAFT;

            if ($space->status !== $status) {
                $app->disableAccessControl();
                self::$syncing = true;

                try {
                    $space->setStatus($status);
                    $space->save(true);
                } finally {
                    self::$syncing = false;
                    $app->enableAccessControl();
                }
            }
        }
    }

    /**
     * "Meus locais" (Controller::GET_places): os locais de quem tem a conta no subsite atual, fora da lixeira, com o
     * endereço e os festejos que têm período em cada um (os que impedem a exclusão).
     *
     * @return list<array{id: int, name: string, status: int, address: string, city: string, uf: string, cep: string, festivities: list<array{id: int, name: string, status: int}>}>
     */
    static function ofUser(\MapasCulturais\Entities\User $user): array
    {
        $app = App::i();
        $subsite = $app->getCurrentSubsiteId();
        $spaces = $app->em->createQuery('SELECT s FROM ' . Space::class . ' s JOIN s.owner a WHERE a.user = :user AND s.status >= :draft AND s._subsiteId ' . ($subsite ? '= :subsite' : 'IS NULL') . ' ORDER BY s.name ASC')
            ->setParameters(['user' => $user, 'draft' => Space::STATUS_DRAFT] + ($subsite ? ['subsite' => $subsite] : []))
            ->getResult();

        $festivities = [];
        if ($spaces) {
            $rows = $app->em->createQuery('SELECT DISTINCT IDENTITY(o.space) AS space, e.id, e.name, e.status FROM ' . EventOccurrence::class . ' o JOIN o.event e WHERE o.space IN (:spaces) AND o.status > 0 ORDER BY e.name ASC')
                ->setParameter('spaces', $spaces)
                ->getArrayResult();

            foreach ($rows as $row) {
                $festivities[(int) $row['space']][] = ['id' => (int) $row['id'], 'name' => $row['name'], 'status' => (int) $row['status']];
            }
        }

        return array_map(fn (Space $space) => [
            'id' => $space->id,
            'name' => (string) $space->name,
            'status' => $space->status,
            'address' => implode(', ', array_filter([(string) $space->En_Nome_Logradouro, (string) $space->En_Num, (string) $space->En_Bairro])),
            'city' => (string) $space->En_Municipio,
            'uf' => (string) $space->En_Estado,
            'cep' => (string) $space->En_CEP,
            'festivities' => $festivities[$space->id] ?? [],
        ], $spaces);
    }
}

