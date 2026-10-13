<?php

namespace MDC\Services;

use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;

/**
 * Paliativo: a ficha do v1 não tem exibição para as fotos (`@gallery`) e os vídeos (`@videos`) do agente,
 * que o core só passou a aceitar no formulário v2, e os mostra como `[object Object]`. Aqui eles viram
 * HTML. Sai quando o `registration-field-view.php` do core tratar esses campos, como já trata `@links`.
 */
class AgentMediaView
{
    const GALLERY = '@gallery';
    const VIDEOS = '@videos';

    /** @return RegistrationFieldConfiguration[] campos de fotos e vídeos do agente que a inscrição exibe */
    static function fieldsOf(Registration $registration): array
    {
        return array_filter(
            $registration->opportunity->registrationFieldConfigurations,
            fn ($field) => $field->fieldType === 'agent-owner-field'
                && in_array($field->config['entityField'] ?? null, [self::GALLERY, self::VIDEOS], true)
                && $registration->isFieldVisisble($field)
        );
    }

    static function toHtml(RegistrationFieldConfiguration $field, array $items): string
    {
        // Converte cada item em array.
        $items = array_map(fn ($item) => (array) $item, $items);

        return $field->config['entityField'] === self::GALLERY ? self::gallery($items) : self::videos($items);
    }

    // Miniaturas lado a lado; cada uma abre a foto inteira.
    private static function gallery(array $photos): string
    {
        $html = '';

        foreach ($photos as $photo) {
            $url = self::linkable($photo['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $html .= sprintf(
                '<a class="mdc-media-view__photo" href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="%2$s"></a>',
                self::escape($url),
                self::escape((string) (($photo['description'] ?? null) ?: ($photo['name'] ?? '')))
            );
        }

        return $html === '' ? '' : "<div class=\"mdc-media-view mdc-media-view--gallery\">{$html}</div>";
    }

    // Um vídeo por linha, com a mesma marcação que o v1 usa para `@links`.
    private static function videos(array $videos): string
    {
        $html = '';

        foreach ($videos as $video) {
            $value = trim((string) ($video['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            $title = trim((string) ($video['title'] ?? ''));
            $url = self::linkable($value);

            $html .= '<div>'
                . ($title === '' ? '' : '<b>' . self::escape($title) . ':</b> ')
                . ($url === null
                    ? self::escape($value)
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', self::escape($url)))
                . '</div>';
        }

        return $html === '' ? '' : "<div class=\"mdc-media-view\">{$html}</div>";
    }

    // Só vira link o endereço http ou https.
    private static function linkable(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
