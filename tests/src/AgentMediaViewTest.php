<?php

namespace Tests\MDC;

use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MDC\Services\AgentMediaView;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class AgentMediaViewTest extends TestCase
{
    use GalleryPhotos,
        UserDirector;

    private function field(string $entity_field): RegistrationFieldConfiguration
    {
        $field = new RegistrationFieldConfiguration();
        $field->fieldType = 'agent-owner-field';
        $field->config = ['entityField' => $entity_field];

        return $field;
    }

    function testShowsThePhotosAsThumbnailsThatOpenTheFullImage()
    {
        $owner = $this->userDirector->createUser()->profile;
        [$roda, $renda] = [$this->galleryPhoto($owner), $this->galleryPhoto($owner)];
        $photos = [
            ['id' => $roda->id, 'name' => 'roda.jpg', 'url' => $roda->url, 'description' => 'Roda de coco'],
            ['id' => $renda->id, 'name' => 'renda.jpg', 'url' => $renda->url, 'description' => null],
        ];

        $this->assertSame(
            '<div class="mdc-media-view mdc-media-view--gallery">'
            . "<a class=\"mdc-media-view__photo\" href=\"{$roda->url}\" target=\"_blank\" rel=\"noopener noreferrer\"><img src=\"{$roda->url}\" alt=\"Roda de coco\"></a>"
            . "<a class=\"mdc-media-view__photo\" href=\"{$renda->url}\" target=\"_blank\" rel=\"noopener noreferrer\"><img src=\"{$renda->url}\" alt=\"renda.jpg\"></a>"
            . '</div>',
            AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos, $owner),
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
        $owner = $this->userDirector->createUser()->profile;

        $this->assertSame(
            '<div class="mdc-media-view"><div><b>&lt;b&gt;&quot;x&quot;&lt;/b&gt;:</b> javascript:alert(1)</div></div>',
            AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos),
            'Endereço que não é http(s) aparece como texto, sem link'
        );

        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos, $owner));
    }

    function testReadsTheItemsAsObjectsTheWayTheCoreDeliversThem()
    {
        $owner = $this->userDirector->createUser()->profile;
        $roda = $this->galleryPhoto($owner);
        $photos = [(object) ['id' => $roda->id, 'name' => 'roda.jpg', 'url' => $roda->url, 'description' => null]];
        $videos = [(object) ['title' => 'Minha história', 'value' => 'https://vimeo.com/123']];

        $this->assertStringContainsString("<img src=\"{$roda->url}\" alt=\"roda.jpg\">", AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos, $owner));
        $this->assertStringContainsString('<b>Minha história:</b>', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), $videos));
    }

    function testShowsNothingWhenThereIsNothingToShow()
    {
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), [], null));
        $this->assertSame('', AgentMediaView::toHtml($this->field(AgentMediaView::VIDEOS), [['title' => 'x', 'value' => '  ']]));
    }

    function testListsThePhotosForTheSpreadsheetByNameAndAddress()
    {
        $owner = $this->userDirector->createUser()->profile;
        [$roda, $renda] = [$this->galleryPhoto($owner), $this->galleryPhoto($owner)];
        $photos = [
            (object) ['id' => $roda->id, 'name' => 'roda.jpg', 'url' => $roda->url],
            ['id' => $renda->id, 'name' => 'renda.jpg', 'url' => $renda->url],
            ['name' => 'sem-id.jpg', 'url' => 'javascript:alert(1)'],
        ];

        $this->assertSame("roda.jpg: {$roda->url} | renda.jpg: {$renda->url}", AgentMediaView::photosAsCell($photos));
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

    // A foto sai pelo id, com o endereço que o core monta, e só da galeria de quem se inscreveu.
    function testShowsOnlyTheOwnersGalleryPhotosWithTheAddressTheCoreBuilds()
    {
        $owner = $this->userDirector->createUser()->profile;
        $mine = $this->galleryPhoto($owner);
        $theirs = $this->galleryPhoto($this->userDirector->createUser()->profile);
        $avatar = $this->galleryPhoto($owner, 'avatar');
        $photos = [
            ['id' => $mine->id, 'name' => 'minha.jpg', 'url' => 'http://evil\\@localhost/files/rastreio.gif'],
            ['id' => $theirs->id, 'name' => 'alheia.jpg', 'url' => $theirs->url],
            ['id' => $avatar->id, 'name' => 'avatar.jpg', 'url' => $avatar->url],
            ['name' => 'sem-id.jpg', 'url' => 'http://localhost/files/../painel/'],
        ];

        $html = AgentMediaView::toHtml($this->field(AgentMediaView::GALLERY), $photos, $owner);

        $this->assertSame(1, substr_count($html, '<img '), 'Só a foto da galeria de quem se inscreveu');
        $this->assertStringContainsString("src=\"{$mine->url}\"", $html, 'Com o endereço do arquivo, e não o gravado');
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('painel', $html);
        $this->assertSame("minha.jpg: {$mine->url} | alheia.jpg: {$theirs->url}", AgentMediaView::photosAsCell($photos), 'Na planilha, a galeria de um agente, também pelo id');
    }
}
