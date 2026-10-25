<?php

namespace Tests\MDC;

/**
 * Cria o subsite dos Mestres nos testes e põe entidades nele. O subsite do programa é o da oportunidade do programa
 * (MDC\Services\FixedOpportunity::subsiteId); o que fica sem subsite é do site principal.
 */
trait ProgramSubsite
{
    private function createProgramSubsite(int $owner): int
    {
        $id = 900;
        $this->app->conn->executeStatement("INSERT INTO subsite (id, name, create_timestamp, status, agent_id, url, namespace) VALUES (:id, 'Mestres', now(), 1, :owner, 'mestres.test', 'MestresDaCultura') ON CONFLICT (id) DO NOTHING", ['id' => $id, 'owner' => $owner]);

        return $id;
    }

    /** A oportunidade e as fases dela (`parent_id`), ou outra entidade, no subsite. */
    private function putInSubsite(string $table, int $id, int $subsite): void
    {
        $where = $table === 'opportunity' ? 'id = :id OR parent_id = :id' : 'id = :id';
        $this->app->conn->executeStatement("UPDATE {$table} SET subsite_id = :subsite WHERE {$where}", ['subsite' => $subsite, 'id' => $id]);
        $this->app->em->clear();
    }
}
