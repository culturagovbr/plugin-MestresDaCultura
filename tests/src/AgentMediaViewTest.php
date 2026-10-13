<?php

namespace Tests\MDC;

use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MDC\Services\AgentMediaView;
use Tests\Abstract\TestCase;

class AgentMediaViewTest extends TestCase
{
    private function field(string $entity_field): RegistrationFieldConfiguration
    {
        $field = new RegistrationFieldConfiguration();
        $field->fieldType = 'agent-owner-field';
        $field->config = ['entityField' => $entity_field];

        return $field;
    }

    function testShowsThePhotosAsThumbnailsThatOpenTheFullImage()
    {
        $photos = [
            ['id' => 1, 'name' => 'roda.jpg', 'url' => 'https://exemplo.org/roda.jpg', 'description' => 'Roda de coco'],
            ['id' => 2, 'name' => 'renda.jpg', 'url' => 'https://exemplo.org/renda.jpg', 'description' => null],
        ];

        $this->assertSame(
            '<div class="mdc-media-view mdc-media-view--gallery">'
            . '<a class="mdc-media-view__photo" href="https://exemplo.org/roda.jpg" target="_blank" rel="noopener noreferrer"><img src="https://exemplo.org/roda.jpg" alt="Roda de coco"></a>'
            . '<a class="mdc-media-view__photo" href="https://exemplo.org/renda.jpg" target="_blank" rel="noopener noreferrer"><img src="https://exemplo.org/renda.jpg" alt="renda.jpg"></a>'
            . '</div>',
            AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos),
            'Sem descrição, o texto alternativo da foto é o nome do arquivo'
        );
    }

    function testShowsTheVideosLikeTheLinksOfTheV1()
    {
        $videos = [
            ['title' => 'Minha história', 'value' => 'https://www.youtube.com/watch?v=abc'],
            ['title' => '', 'value' => 'https://vimeo.com/123'],
        ];

        $this->assertSame(
            '<div class="mdc-media-view">'
            . '<div><b>Minha história:</b> <a href="https://www.youtube.com/watch?v=abc" target="_blank" rel="noopener noreferrer">https://www.youtube.com/watch?v=abc</a></div>'
            . '<div><a href="https://vimeo.com/123" target="_blank" rel="noopener noreferrer">https://vimeo.com/123</a></div>'
            . '</div>',
            AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos)
        );
    }

    function testLinksOnlyHttpAddressesAndEscapesWhatThePersonTyped()
    {
        $videos = [['title' => '<b>"x"</b>', 'value' => 'javascript:alert(1)']];
        $photos = [['name' => 'x.jpg', 'url' => 'javascript:alert(1)', 'description' => null]];

        $this->assertSame(
            '<div class="mdc-media-view"><div><b>&lt;b&gt;&quot;x&quot;&lt;/b&gt;:</b> javascript:alert(1)</div></div>',
            AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos),
            'Endereço que não é http(s) aparece como texto, sem link'
        );

        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos));
    }

    function testReadsTheItemsAsObjectsTheWayTheCoreDeliversThem()
    {
        $photos = [(object) ['id' => 1, 'name' => 'roda.jpg', 'url' => 'https://exemplo.org/roda.jpg', 'description' => null]];
        $videos = [(object) ['title' => 'Minha história', 'value' => 'https://vimeo.com/123']];

        $this->assertStringContainsString('<img src="https://exemplo.org/roda.jpg" alt="roda.jpg">', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos));
        $this->assertStringContainsString('<b>Minha história:</b>', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos));
    }

    function testShowsNothingWhenThereIsNothingToShow()
    {
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), []));
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), [['title' => 'x', 'value' => '  ']]));
    }
}
