<?php

namespace MDC\Services;

use MapasCulturais\App;
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
            $url = self::ownFile($photo['url'] ?? null);

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

    // Fotos para a planilha de inscrições: "nome: endereço", separados por " | ".
    static function photosAsCell(array $photos): string
    {
        $cells = [];

        foreach ($photos as $photo) {
            $photo = (array) $photo;
            $url = self::ownFile($photo['url'] ?? null);

            if ($url !== null) {
                $cells[] = (($photo['description'] ?? null) ?: ($photo['name'] ?? '')) . ": {$url}";
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
     * A foto da galeria é um arquivo da própria instalação (o Mapa ou um subsite, em /files/). O endereço vem do que o
     * formulário gravou na resposta: um de fora viraria, na ficha de quem avalia, uma imagem carregada de outro site.
     */
    private static function ownFile(mixed $url): ?string
    {
        static $hosts = null;

        if (!$url = self::linkable($url)) {
            return null;
        }

        // O Mapa e os subsites (o endereço do subsite é gravado sem o protocolo).
        $hosts ??= array_filter(array_map(
            fn ($address) => parse_url(str_contains((string) $address, '://') ? $address : "http://{$address}", PHP_URL_HOST),
            [App::i()->baseUrl, ...App::i()->conn->fetchFirstColumn('SELECT url FROM subsite UNION SELECT alias_url FROM subsite')]
        ));
        $ownHost = in_array(parse_url($url, PHP_URL_HOST), $hosts, true);

        return $ownHost && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/files/') ? $url : null;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
