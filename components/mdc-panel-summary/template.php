<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-card
    mc-icon
');
?>
<div class="mdc-panel-summary">
    <section v-if="moderation" class="mdc-panel-summary__block">
        <h2 class="mdc-panel-summary__title">{{ text('moderação') }}</h2>

        <mc-card>
            <template #content>
                <p class="mdc-panel-summary__lead" role="status">
                    <strong class="mdc-panel-summary__number">{{ moderation.counts.pending }}</strong>
                    {{ moderation.counts.pending === 1 ? text('pendente com você') : text('pendentes com você') }}
                </p>

                <ul class="mdc-panel-summary__counts">
                    <li><strong>{{ moderation.counts.started }}</strong> {{ text('iniciadas') }}</li>
                    <li><strong>{{ moderation.counts.completed }}</strong> {{ text('concluídas') }}</li>
                    <li><strong>{{ moderation.counts.sent }}</strong> {{ text('enviadas') }}</li>
                </ul>

                <a class="button button--primary button--icon mdc-panel-summary__action" :href="moderation.url">
                    {{ text('ir para a moderação') }} <mc-icon name="arrow-right"></mc-icon>
                </a>
            </template>
        </mc-card>
    </section>

    <section v-if="management" class="mdc-panel-summary__block">
        <h2 class="mdc-panel-summary__title">{{ text('gestão') }}</h2>

        <mc-card>
            <template #content>
                <ul class="mdc-panel-summary__situations" :aria-label="text('cadastros por situação')">
                    <li v-for="situation in situations" :key="situation.slug" :class="'mdc-panel-summary__situation--' + situation.slug">
                        <strong class="mdc-panel-summary__number">{{ situation.count }}</strong>
                        <span>{{ situation.label }}</span>
                    </li>
                </ul>

                <nav class="mdc-panel-summary__shortcuts" :aria-label="text('gestão')">
                    <a v-for="shortcut in management.shortcuts" :key="shortcut.url" class="button button--primary-outline button--icon" :href="shortcut.url">
                        <mc-icon :name="shortcut.icon"></mc-icon> {{ shortcut.label }}
                    </a>
                </nav>
            </template>
        </mc-card>
    </section>
</div>
