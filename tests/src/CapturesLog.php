<?php

namespace Tests\MDC;

use Monolog\Handler\TestHandler;

/**
 * Manda o log só para o TestHandler; os destinos originais voltam no tearDown.
 */
trait CapturesLog
{
    private ?array $logHandlers = null;

    private function captureLog(): TestHandler
    {
        $log = new TestHandler();
        $this->logHandlers ??= $this->app->log->getHandlers();
        $this->app->log->setHandlers([$log]);

        return $log;
    }

    protected function tearDown(): void
    {
        if ($this->logHandlers !== null) {
            $this->app->log->setHandlers($this->logHandlers);
        }

        parent::tearDown();
    }
}
