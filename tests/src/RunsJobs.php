<?php

namespace Tests\MDC;

trait RunsJobs
{
    /**
     * Roda os jobs vencidos até agora, um a um, com o cache da requisição limpo antes de cada um.
     */
    private function runJobs(): void
    {
        $user = $this->app->user;
        $due = fn () => (int) $this->app->conn->fetchOne('SELECT count(*) FROM job WHERE status = 0 AND iterations_count < iterations AND next_execution_timestamp <= :now', ['now' => date('Y-m-d H:i:s')]);

        for ($round = 0; $round < 20 && $due(); $round++) {
            $this->app->rcache->deleteAll();
            $this->app->executeJob();
        }

        $this->login($user);
    }
}
