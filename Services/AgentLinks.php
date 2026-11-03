<?php

namespace MDC\Services;

use MapasCulturais\Entities\MetaList;
use MapasCulturais\Entity;

/**
 * Links do agente gravados pelo campo `@links` do cadastro: só o grupo de links muda. Endereço de vídeo (YouTube,
 * Vimeo) vai para os vídeos, como no core, sem duplicar o que já está lá.
 */
final class AgentLinks
{
    /** @param array|object|string|null $value a lista do campo, em arrays ou objetos com `value` e `title` */
    static function save(Entity $owner, $value): void
    {
        $wanted = self::items($value);

        foreach ($owner->getMetaLists('links') as $saved) {
            if (!isset($wanted[$saved->value])) {
                $saved->delete(true);
                continue;
            }
            if ($saved->title !== $wanted[$saved->value]) {
                $saved->title = $wanted[$saved->value];
                $saved->save(true);
            }
            unset($wanted[$saved->value]);
        }

        foreach ($owner->getMetaLists('videos') as $video) {
            unset($wanted[$video->value]);
        }

        foreach ($wanted as $url => $title) {
            $item = new MetaList;
            $item->owner = $owner;
            $item->group = self::isVideo($url) ? 'videos' : 'links';
            $item->title = $title;
            $item->value = $url;
            $item->save(true);
        }
    }

    /** @return array<string, string> endereço => título, sem os itens sem endereço */
    private static function items($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value);
        }

        $items = [];
        foreach ((array) $value as $item) {
            $item = (array) $item;
            $url = trim((string) ($item['value'] ?? ''));
            if ($url !== '') {
                $items[$url] = (string) ($item['title'] ?? '');
            }
        }

        return $items;
    }

    // A mesma regra do core para o endereço que vira vídeo.
    private static function isVideo(string $url): bool
    {
        return strpos($url, 'youtube') > 0 || strpos($url, 'youtu.be') > 0 || strpos($url, 'vimeo') > 0;
    }
}
