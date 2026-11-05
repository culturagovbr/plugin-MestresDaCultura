<?php

namespace Tests\MDC;

use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// A página diz à tela quais páginas não abrem para quem vê, para os nomes delas aparecerem sem link.
class ClosedPagesTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    private function closedPagesOf(int $eventId): array
    {
        $this->app->reset();
        $this->app->run($this->requestFactory->GET('event', 'single', [$eventId]), false);

        return $this->app->view->jsObject['config']['mdcClosedPages'] ?? [];
    }

    function testSpacesProjectsAndTheOwnerWithoutTheSealAreClosed()
    {
        $event = $this->draft();
        $this->app->conn->executeStatement('UPDATE event SET status = 1 WHERE id = ?', [$event->id]);
        $this->app->em->clear();
        $this->login($this->userDirector->createUser());

        $closed = $this->closedPagesOf($event->id);

        $this->assertSame(['space', 'project'], $closed['types'] ?? null);
        $this->assertSame([$event->owner->id], $closed['agents'] ?? null, 'A dona do festejo, sem o selo de mestra');
    }

    function testTheOwnerSeesTheOwnProfileLink()
    {
        $event = $this->draft();

        $this->assertSame([], $this->closedPagesOf($event->id)['agents'] ?? null);
    }
}
