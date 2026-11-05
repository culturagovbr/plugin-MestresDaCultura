<?php

namespace Tests\MDC;

use MapasCulturais\Entities\EventFile;
use MapasCulturais\Entities\MetaList;
use MDC\Services\FestivityFields;
use Tests\Abstract\TestCase;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

// Ao continuar o festejo, o formulário recebe as imagens, os anexos e os vídeos já enviados.
class FestivityFormFilesTest extends TestCase
{
    use BuildsFestivities;
    use RequestFactory;
    use UserDirector;

    function testTheFormBringsTheFilesAndTheVideos()
    {
        $event = $this->draft();

        $path = sys_get_temp_dir() . '/mdc-lei-' . uniqid() . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF");
        $file = new EventFile(['error' => UPLOAD_ERR_OK, 'name' => 'lei.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'size' => filesize($path)]);
        $file->owner = $event;
        $file->group = FestivityFields::LAW_FILE;
        $file->save(true);

        $video = new MetaList;
        $video->owner = $event;
        $video->group = 'videos';
        $video->title = 'O festejo';
        $video->value = 'https://www.youtube.com/watch?v=abc123';
        $video->save(true);

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'festivity', [$event->id]), false);
        $requested = $this->app->view->jsObject['requestedEntity'] ?? [];

        $this->assertSame($event->id, $requested['id'] ?? null);
        $this->assertArrayHasKey(FestivityFields::LAW_FILE, (array) ($requested['files'] ?? []), 'O arquivo da lei');
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $requested['metalists']['videos'][0]->value ?? $requested['metalists']['videos'][0]['value'] ?? null, 'O vídeo');
    }

    function testThePictureButtonSpeaksOfTheFestivity()
    {
        $event = $this->draft();

        $this->app->reset();
        $this->app->run($this->requestFactory->GET('mdc', 'festivity', [$event->id]), false);

        $this->assertSame('Adicionar foto do festejo', \MapasCulturais\i::__('Adicionar imagem de perfil'));
    }
}
