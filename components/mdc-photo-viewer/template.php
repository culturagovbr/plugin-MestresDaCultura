<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-modal
');
?>
<mc-modal ref="modal" :title="title" classes="mdc-photo-viewer">
    <img v-if="current" class="mdc-photo-viewer__image" :src="current.src" :alt="current.alt">

    <template v-if="photos.length > 1" #actions>
        <button type="button" class="button button--text button--icon" @click="previous()">
            <mc-icon name="previous"></mc-icon> {{text('anterior')}}
        </button>
        <button type="button" class="button button--text button--icon" @click="next()">
            {{text('proxima')}} <mc-icon name="next"></mc-icon>
        </button>
    </template>
</mc-modal>
