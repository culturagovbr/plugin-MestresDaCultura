<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MDC\Plugin;
use Tests\Abstract\TestCase;

class TraditionalCommunitiesTest extends TestCase
{
    function testAddsTheMissingCommunitiesInOrderKeepingTheNoAnswersOnTop()
    {
        $options = Plugin::withTraditionalCommunities(['' => 'Não informado', 'Não pertenço' => 'Não pertenço', 'Caiçaras' => 'Caiçaras', 'Ilhéus' => 'Ilhéus', 'Veredeiros' => 'Veredeiros', 'Seringueiros' => 'Seringueiros']);

        $this->assertSame(['', 'Não pertenço', 'Caiçaras', 'Castanheiras', 'Ilhéus', 'Isqueiros', 'Piaçaveiros', 'Seringueiros', 'Veredeiros'], array_keys($options), 'Sem repetir o que já existe');
    }

    function testTheAgentProfileAcceptsTheNewCommunities()
    {
        foreach ([null, ...$this->app->getRegisteredEntityTypes(Agent::class)] as $type) {
            $options = $this->app->getRegisteredMetadataByMetakey('comunidadesTradicional', Agent::class, $type?->id)?->options ?? [];

            $this->assertEmpty(array_diff(Plugin::TRADITIONAL_COMMUNITIES, array_keys($options)), 'Tipo ' . ($type?->id ?? 'geral'));
        }
    }
}
