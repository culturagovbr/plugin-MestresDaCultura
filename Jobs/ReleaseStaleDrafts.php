<?php

namespace MDC\Jobs;

use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;
use MDC\Services\ModerationQueue;

/** Uma vez por dia, os rascunhos de moderação parados voltam para a fila (ver Services\ModerationQueue). */
class ReleaseStaleDrafts extends JobType
{
    const SLUG = 'mdc-release-stale-drafts';

    /** Repetições do job diário, na prática sem fim. */
    const ITERATIONS = 36500;

    /** Um só na fila. */
    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG;
    }

    static function schedule(): void
    {
        App::i()->enqueueJob(self::SLUG, [], 'tomorrow 03:00', '+1 day', self::ITERATIONS);
    }

    // Um erro não tira o job da fila: ele tenta de novo no dia seguinte.
    protected function _execute(Job $job)
    {
        try {
            (new ModerationQueue)->releaseStaleDrafts();
        } catch (\Throwable $e) {
            App::i()->log->error('MDC: a devolução dos rascunhos parados falhou; nova tentativa amanhã: ' . $e->getMessage());
        }

        return true;
    }
}
