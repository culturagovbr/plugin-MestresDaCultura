<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-card
    mc-icon
    mc-status
');
?>
<div v-if="approved" class="mdc-moderation-notice">
    <mc-card class="mdc-moderation-notice__card">
        <template #title>
            <h3>{{ text('seu cadastro') }}</h3>
            <mc-status :status-name="text('status aprovado')"></mc-status>
        </template>

        <template #content>
            <p class="mdc-moderation-notice__approved">{{ text('aprovado texto') }}</p>

            <footer class="mdc-moderation-notice__footer">
                <p class="mdc-moderation-notice__note">{{ text('certificado nota') }}</p>
                <a class="button button--primary button--icon" :href="moderation.certificateUrl">
                    <mc-icon name="print"></mc-icon> {{ text('ver certificado') }}
                </a>
            </footer>
        </template>
    </mc-card>
</div>

<div v-else class="mdc-moderation-notice mdc-moderation-notice--pending">
    <div class="mdc-moderation-notice__alert" role="status">
        <mc-icon name="exclamation"></mc-icon>
        <p><strong>{{ text('aviso título') }}</strong> {{ text('aviso texto') }}</p>
    </div>

    <mc-card class="mdc-moderation-notice__card">
        <template #title>
            <h3>{{ text('seu cadastro') }}</h3>
            <mc-status :status-name="text('pendente de moderação')"></mc-status>
        </template>

        <template #content>
            <ul class="mdc-moderation-notice__fields">
                <li v-for="field in fields" :key="field.name" class="mdc-moderation-notice__field" :class="'mdc-moderation-notice__field--' + field.result">
                    <mc-icon :name="field.result === 'invalid' ? 'mdc-not-proven' : 'circle-checked'"></mc-icon>
                    <div class="mdc-moderation-notice__field-body">
                        <p class="mdc-moderation-notice__field-title">
                            {{ title(field) }} — {{ field.result === 'invalid' ? text('não comprovado') : text('aprovado') }}
                        </p>
                        <p v-if="field.result === 'invalid' && field.section" class="mdc-moderation-notice__field-label">{{ field.label }}</p>
                        <p v-if="field.result === 'invalid' && field.obs" class="mdc-moderation-notice__field-obs">{{ field.obs }}</p>
                    </div>
                </li>
            </ul>

            <footer class="mdc-moderation-notice__footer">
                <p class="mdc-moderation-notice__note">{{ text('enquanto pendente') }}</p>
                <a class="button button--primary button--icon" :href="moderation.editUrl">
                    {{ text('corrigir') }} <mc-icon name="arrow-right"></mc-icon>
                </a>
            </footer>
        </template>
    </mc-card>
</div>
