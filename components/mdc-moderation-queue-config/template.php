<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;
use MDC\Services\ModerationQueue;

$this->import('
    entity-field
');
?>
<section class="mdc-moderation-queue-config">
    <h4 class="mdc-moderation-queue-config__title"><?= i::__('Fila da moderação') ?></h4>
    <p class="mdc-moderation-queue-config__description"><?= i::__('O cadastro que fica com um moderador que não vai analisá-lo volta para a fila e passa a outro. Concluídas e enviadas não mudam.') ?></p>

    <entity-field :entity="entity" prop="<?= ModerationQueue::RELEASE_DISABLED ?>" type="checkbox" :autosave="300"></entity-field>
    <entity-field :entity="entity" prop="<?= ModerationQueue::RELEASE_STALE ?>" type="checkbox" :autosave="300"></entity-field>
    <entity-field v-if="entity.<?= ModerationQueue::RELEASE_STALE ?>" :entity="entity" prop="<?= ModerationQueue::STALE_DAYS ?>" :autosave="300"></entity-field>
</section>
