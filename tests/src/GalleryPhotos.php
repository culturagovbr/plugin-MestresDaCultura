<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentFile;

/** Grava uma foto de verdade na galeria do agente. */
trait GalleryPhotos
{
    private function galleryPhoto(Agent $owner, string $group = 'gallery'): AgentFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
        imagejpeg(imagecreatetruecolor(300, 300), $tmp);

        $this->app->disableAccessControl();
        $photo = new AgentFile(['error' => UPLOAD_ERR_OK, 'name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
        $photo->group = $group;
        $photo->owner = $owner;
        $photo->save(true);
        $this->app->enableAccessControl();

        return $photo;
    }
}
