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
            ['id' => 1, 'name' => 'roda.jpg', 'url' => 'http://localhost/files/agent/1/roda.jpg', 'description' => 'Roda de coco'],
            ['id' => 2, 'name' => 'renda.jpg', 'url' => 'http://localhost/files/agent/1/renda.jpg', 'description' => null],
        ];

        $this->assertSame(
            '<div class="mdc-media-view mdc-media-view--gallery">'
            . '<a class="mdc-media-view__photo" href="http://localhost/files/agent/1/roda.jpg" target="_blank" rel="noopener noreferrer"><img src="http://localhost/files/agent/1/roda.jpg" alt="Roda de coco"></a>'
            . '<a class="mdc-media-view__photo" href="http://localhost/files/agent/1/renda.jpg" target="_blank" rel="noopener noreferrer"><img src="http://localhost/files/agent/1/renda.jpg" alt="renda.jpg"></a>'
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
        $photos = [(object) ['id' => 1, 'name' => 'roda.jpg', 'url' => 'http://localhost/files/agent/1/roda.jpg', 'description' => null]];
        $videos = [(object) ['title' => 'Minha história', 'value' => 'https://vimeo.com/123']];

        $this->assertStringContainsString('<img src="http://localhost/files/agent/1/roda.jpg" alt="roda.jpg">', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos));
        $this->assertStringContainsString('<b>Minha história:</b>', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos));
    }

    function testShowsNothingWhenThereIsNothingToShow()
    {
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), []));
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), [['title' => 'x', 'value' => '  ']]));
    }

    function testListsThePhotosForTheSpreadsheetByNameAndAddress()
    {
        $photos = [
            (object) ['name' => 'roda.jpg', 'url' => 'http://localhost/files/agent/1/roda.jpg'],
            ['name' => 'renda.jpg', 'url' => 'http://localhost/files/agent/1/renda.jpg'],
            ['name' => 'sem-endereco.jpg', 'url' => 'javascript:alert(1)'],
            ['name' => 'de-fora.jpg', 'url' => 'https://exemplo.org/files/rastreio.gif'],
        ];

        $this->assertSame('roda.jpg: http://localhost/files/agent/1/roda.jpg | renda.jpg: http://localhost/files/agent/1/renda.jpg', AgentMediaView::photosAsCell($photos));
    }

    function testWritesTheVideosAndLinksForTheSpreadsheetWithTheirTitles()
    {
        $videos = [
            ['id' => 1, 'title' => 'Minha história', 'value' => 'https://vimeo.com/123', 'group' => 'videos'],
            (object) ['title' => '', 'value' => 'https://youtu.be/abc'],
            ['title' => 'Sem endereço', 'value' => ' '],
        ];

        $this->assertSame('Minha história: https://vimeo.com/123 | https://youtu.be/abc', AgentMediaView::linksAsCell($videos));
    }

    // O endereço da foto vem do que o formulário gravou: um de fora viraria, na ficha de quem avalia, uma imagem
    // carregada de outro site (e o aviso a ele de quem abriu e quando). Só arquivos da própria instalação.
    function testShowsOnlyPhotosThatAreFilesOfTheInstallation()
    {
        $photos = [
            ['name' => 'roda.jpg', 'url' => 'http://localhost/files/agent/1/roda.jpg'],
            ['name' => 'rastreio.gif', 'url' => 'https://exemplo.org/files/rastreio.gif'],
            ['name' => 'fora-de-files.jpg', 'url' => 'http://localhost/outra/pasta.jpg'],
        ];

        $html = AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos);

        $this->assertStringContainsString('roda.jpg', $html);
        $this->assertStringNotContainsString('exemplo.org', $html, 'A de outro site não entra');
        $this->assertStringNotContainsString('outra/pasta', $html, 'Nem o que não é arquivo');
        $this->assertSame('roda.jpg: http://localhost/files/agent/1/roda.jpg', AgentMediaView::photosAsCell($photos), 'Na planilha também');
    }
}
