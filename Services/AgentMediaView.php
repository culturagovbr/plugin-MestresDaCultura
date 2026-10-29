<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentFile;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;

/**
 * Mostra em HTML, na ficha do v1, as fotos (`@gallery`) e os vídeos (`@videos`) do agente,
 * e escreve fotos, vídeos e links (`@links`) como texto na planilha de inscrições.
 */
class AgentMediaView
{
    const GALLERY = '@gallery';
    const VIDEOS = '@videos';
    const LINKS = '@links';

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

    // Fotos ou vídeos em HTML; as fotos, só as da galeria de quem se inscreveu (`$owner`).
    static function toHtml(RegistrationFieldConfiguration $field, array $items, ?Agent $owner = null): string
    {
        // Converte cada item em array.
        $items = array_map(fn ($item) => (array) $item, $items);

        return $field->config['entityField'] === self::GALLERY ? self::gallery($items, $owner) : self::videos($items);
    }

    // Miniaturas lado a lado; cada uma abre a foto inteira.
    private static function gallery(array $photos, ?Agent $owner): string
    {
        $html = '';
        $files = self::galleryFiles($photos);

        foreach ($photos as $photo) {
            $file = $files[(int) ($photo['id'] ?? 0)] ?? null;

            if (!$owner || $file?->owner?->id !== $owner->id) {
                continue;
            }

            $url = $file->url;

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

    // Fotos para a planilha de inscrições: "nome: endereço", separados por " | ".
    static function photosAsCell(array $photos): string
    {
        $cells = [];
        $photos = array_map(fn ($photo) => (array) $photo, $photos);
        $files = self::galleryFiles($photos);

        foreach ($photos as $photo) {
            if ($file = $files[(int) ($photo['id'] ?? 0)] ?? null) {
                $cells[] = (($photo['description'] ?? null) ?: ($photo['name'] ?? '')) . ": {$file->url}";
            }
        }

        return implode(' | ', $cells);
    }

    // Vídeos e links para a planilha de inscrições: "Título: endereço", separados por " | ".
    static function linksAsCell(array $links): string
    {
        $cells = [];

        foreach ($links as $link) {
            $link = (array) $link;
            $value = trim((string) ($link['value'] ?? ''));
            $title = trim((string) ($link['title'] ?? ''));

            if ($value !== '') {
                $cells[] = $title === '' ? $value : "{$title}: {$value}";
            }
        }

        return implode(' | ', $cells);
    }

    // Só vira link o endereço http ou https.
    private static function linkable(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    /**
     * As fotos pelo id: só arquivo da galeria de um agente, com o endereço que o core monta.
     *
     * @return array<int,AgentFile> id => arquivo
     */
    private static function galleryFiles(array $photos): array
    {
        $ids = array_filter(array_map(fn ($photo) => (int) ($photo['id'] ?? 0), $photos));
        $files = $ids ? App::i()->repo('AgentFile')->findBy(['id' => array_values($ids), 'group' => 'gallery']) : [];

        return array_column(array_map(fn ($file) => [$file->id, $file], $files), 1, 0);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
