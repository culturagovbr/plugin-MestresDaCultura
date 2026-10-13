<?php

namespace MDC\Services;

use Doctrine\DBAL\ArrayParameterType;
use MapasCulturais\App;
use MapasCulturais\Definitions\Metadata as MetadataDefinition;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\i;
use MDC\Entities\Node;

/**
 * Campo da pergunta "Atuação Cultural": grava um espelho dos nós marcados e do texto de cada "Outro":
 * `{"nodes": [{"id", "parent", "name"}], "others": [{"parent": id|null, "text"}]}` (`parent: null` é o "Outro" geral).
 * Até o envio, os nomes seguem a árvore atual; depois dele, o que foi gravado não muda mais.
 */
class ActingField
{
    const SLUG = 'mdc-atuacao';

    const OTHER_MAX_LENGTH = 255;

    /** Chave que agrupa os itens sem pai. */
    private const ROOT = 0;

    static function definition(): array
    {
        return [
            'slug' => self::SLUG,
            'name' => i::__('Árvore de atuação cultural'),
            'viewTemplate' => 'registration-field-types/' . self::SLUG,
            'configTemplate' => 'registration-field-types/' . self::SLUG . '-config',
            'serialize' => [self::class, 'serialize'],
            'unserialize' => [self::class, 'unserialize'],
            // Roda ao salvar; no envio, quem valida é o gancho de `Plugin::validateActingFieldOnSend`.
            'validations' => [
                '\MDC\Services\ActingField::isValid($value)' => self::invalidMessage(),
            ],
        ];
    }

    static function invalidMessage(): string
    {
        return i::__('A seleção de atuação tem itens inválidos. Recarregue a página e confira a seleção.');
    }

    static function staleMessage(array $removed): string
    {
        return sprintf(i::__('Saíram da lista de atuação: %s. Recarregue a página e confira a seleção antes de enviar.'), implode(', ', $removed));
    }

    /** @return RegistrationFieldConfiguration[] campos deste tipo que a inscrição exibe */
    static function fieldsOf(Registration $registration): array
    {
        return array_filter(
            $registration->opportunity->registrationFieldConfigurations,
            fn ($field) => $field->fieldType === self::SLUG && $registration->isFieldVisisble($field)
        );
    }

    // Os nomes vêm do banco, nunca do navegador. Depois do envio, o que já estava gravado é mantido.
    static function serialize(mixed $value, mixed $owner = null, ?MetadataDefinition $definition = null): ?string
    {
        $chosen = self::normalize($value);

        if (!$chosen) {
            return null;
        }

        $sent = $owner instanceof Registration && $owner->status !== Registration::STATUS_DRAFT;
        $kept = $sent && $definition ? self::unserialize($owner->getMetadata($definition->key)) : null;

        return json_encode(self::snapshot($chosen, $kept), JSON_UNESCAPED_UNICODE);
    }

    static function unserialize(mixed $value): ?array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * O que, no espelho, já não está na árvore atual: nó desativado, a subárvore escolhida abaixo dele
     * e os "Outro" de dentro dela. Recebe um espelho que já passou por `isValid`.
     *
     * @return string[] nomes, como foram gravados
     */
    static function removedFromTree(array $value): array
    {
        $current = self::currentNodes(array_filter(array_column($value['nodes'] ?? [], 'id'), 'is_int'));

        $gone = [];
        foreach ($value['nodes'] ?? [] as $node) {
            if (!($current[$node['id']]['enabled'] ?? false)) {
                $gone[$node['id']] = $node['name'];
            }
        }

        // Marca os descendentes dos removidos, em qualquer ordem do espelho.
        do {
            $changed = false;

            foreach ($value['nodes'] ?? [] as $node) {
                if (!isset($gone[$node['id']]) && isset($gone[$node['parent']])) {
                    $gone[$node['id']] = $node['name'];
                    $changed = true;
                }
            }
        } while ($changed);

        $removed = array_values($gone);

        foreach ($value['others'] ?? [] as $other) {
            if (isset($gone[$other['parent']])) {
                $removed[] = sprintf(i::__('Outro: %s'), $other['text']);
            }
        }

        return $removed;
    }

    /**
     * A resposta em cartões, um por segmento, com a árvore escolhida dentro, montada só com o espelho
     * gravado, para as telas de leitura. Cada "Outro" fica no fim da lista onde foi escrito; o geral,
     * depois dos cartões. O visual está em `assets-src/sass/4-components/_c-mdc-acting-view.scss`.
     */
    static function toHtml(array $value): string
    {
        $children_of = self::groupByParent($value);
        $html = '';

        foreach ($children_of[self::ROOT]['nodes'] ?? [] as $segment) {
            $html .= '<div class="mdc-acting-view__segment">'
                . '<div class="mdc-acting-view__title">' . self::escape($segment['name']) . '</div>'
                . self::htmlList($children_of, $segment['id'], 'mdc-acting-view__tree')
                . '</div>';
        }

        foreach ($children_of[self::ROOT]['others'] ?? [] as $text) {
            $html .= '<p class="mdc-acting-view__other">' . self::escape(sprintf(i::__('Outro (geral): %s'), $text)) . '</p>';
        }

        return $html === '' ? '' : "<div class=\"mdc-acting-view\">{$html}</div>";
    }

    /**
     * A resposta em texto, um caminho por linha ("Saberes e Ofícios › Artesanato › Couro › Bruacas"),
     * para o PDF da ficha. Cada "Outro" sai no caminho onde foi escrito; o geral, por último.
     */
    static function toText(array $value, string $separator = "\n"): string
    {
        return implode($separator, self::textPaths(self::groupByParent($value), self::ROOT, []));
    }

    // A resposta numa linha só, para a célula da planilha de inscrições.
    static function toCell(array $value): string
    {
        return self::toText($value, ' | ');
    }

    // A resposta em texto, para o PDF da ficha.
    static function unserializeAsText(mixed $value): ?string
    {
        $snapshot = self::unserialize($value);

        return $snapshot ? self::toText($snapshot) : null;
    }

    // Confere só a estrutura do espelho.
    static function isValid(mixed $value): bool
    {
        $nodes = $value['nodes'] ?? null;
        $others = $value['others'] ?? null;

        if (!is_array($nodes) || !is_array($others)) {
            return false;
        }

        $parent_of = [];

        foreach ($nodes as $node) {
            $id = $node['id'] ?? null;
            $parent = $node['parent'] ?? null;

            if (!is_int($id) || array_key_exists($id, $parent_of) || !is_string($node['name'] ?? null)) {
                return false;
            }

            if ($parent !== null && !is_int($parent)) {
                return false;
            }

            $parent_of[$id] = $parent;
        }

        foreach ($parent_of as $parent) {
            if ($parent !== null && !array_key_exists($parent, $parent_of)) {
                return false;
            }
        }

        foreach ($others as $other) {
            $text = $other['text'] ?? null;
            $parent = $other['parent'] ?? null;

            if (!is_string($text) || mb_strlen($text) > self::OTHER_MAX_LENGTH) {
                return false;
            }

            if ($parent !== null && (!is_int($parent) || !array_key_exists($parent, $parent_of))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ids escolhidos e "Outro" preenchidos, aceitando ids soltos ou entradas do espelho. Só arruma a forma.
     *
     * @return ?array{nodes: array, others: array}
     */
    private static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        // Converte objeto e stdClass em array.
        $value = json_decode(json_encode($value), true);

        if (!is_array($value)) {
            return null;
        }

        $ids = [];
        foreach ((array) ($value['nodes'] ?? []) as $node) {
            $id = is_array($node) ? ($node['id'] ?? null) : $node;
            $ids[] = is_numeric($id) ? (int) $id : $id;
        }

        $others = [];
        foreach ((array) ($value['others'] ?? []) as $other) {
            $text = is_array($other) && is_string($other['text'] ?? null) ? trim($other['text']) : '';

            if ($text === '') {
                continue;
            }

            $parent = $other['parent'] ?? null;
            $others[] = ['parent' => is_numeric($parent) ? (int) $parent : $parent, 'text' => $text];
        }

        if (!$ids && !$others) {
            return null;
        }

        return ['nodes' => array_values(array_unique($ids, SORT_REGULAR)), 'others' => $others];
    }

    /**
     * Monta o espelho: o que está em `$kept` fica como foi gravado; o resto vem da árvore atual, mesmo
     * desativado. Id que não existe vira entrada sem nome.
     */
    private static function snapshot(array $chosen, ?array $kept): array
    {
        $kept_entry = [];
        foreach ($kept['nodes'] ?? [] as $entry) {
            $kept_entry[$entry['id']] = $entry;
        }

        $current = self::currentNodes(array_filter($chosen['nodes'], fn ($id) => is_int($id) && !isset($kept_entry[$id])));

        $nodes = [];

        foreach ($chosen['nodes'] as $id) {
            if (is_int($id) && isset($kept_entry[$id])) {
                $nodes[] = $kept_entry[$id];
            } elseif (is_int($id) && isset($current[$id])) {
                $nodes[] = ['id' => $id, 'parent' => $current[$id]['parent'], 'name' => $current[$id]['name']];
            } else {
                $nodes[] = ['id' => $id, 'parent' => null, 'name' => null];
            }
        }

        return ['nodes' => $nodes, 'others' => $chosen['others']];
    }

    /** @return array<int,array{nodes?: array, others?: string[]}> itens e textos de "Outro", por id do pai */
    private static function groupByParent(array $value): array
    {
        $ids = array_column($value['nodes'] ?? [], 'id');
        $children_of = [];

        // Item com pai fora do espelho vai para a raiz.
        foreach ($value['nodes'] ?? [] as $node) {
            $parent = in_array($node['parent'], $ids, true) ? $node['parent'] : self::ROOT;
            $children_of[$parent]['nodes'][] = $node;
        }

        foreach ($value['others'] ?? [] as $other) {
            $parent = in_array($other['parent'], $ids, true) ? $other['parent'] : self::ROOT;
            $children_of[$parent]['others'][] = $other['text'];
        }

        return $children_of;
    }

    // Um caminho por linha, até o último item escolhido de cada ramo; os "Outro" fecham o caminho onde estão.
    private static function textPaths(array $children_of, int $parent, array $path): array
    {
        $lines = [];

        foreach ($children_of[$parent]['nodes'] ?? [] as $node) {
            $node_path = [...$path, $node['name']];
            $below = self::textPaths($children_of, $node['id'], $node_path);

            array_push($lines, ...($below ?: [implode(' › ', $node_path)]));
        }

        foreach ($children_of[$parent]['others'] ?? [] as $text) {
            $label = $parent === self::ROOT ? sprintf(i::__('Outro (geral): %s'), $text) : sprintf(i::__('Outro: %s'), $text);
            $lines[] = implode(' › ', [...$path, $label]);
        }

        return $lines;
    }

    private static function htmlList(array $children_of, int $parent, string $class = ''): string
    {
        $items = '';

        foreach ($children_of[$parent]['nodes'] ?? [] as $node) {
            $items .= '<li>' . self::escape($node['name']) . self::htmlList($children_of, $node['id']) . '</li>';
        }

        foreach ($children_of[$parent]['others'] ?? [] as $text) {
            $items .= '<li class="mdc-acting-view__other">' . self::escape(sprintf(i::__('Outro: %s'), $text)) . '</li>';
        }

        if ($items === '') {
            return '';
        }

        return $class ? "<ul class=\"{$class}\">{$items}</ul>" : "<ul>{$items}</ul>";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }

    /** @return array<int,array{parent: ?int, name: string, enabled: bool}> */
    private static function currentNodes(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $rows = App::i()->em->getConnection()->fetchAllAssociative(
            'SELECT id, parent_id, name, status FROM mdc_tree_node WHERE id IN (?)',
            [array_values($ids)],
            [ArrayParameterType::INTEGER]
        );

        $nodes = [];

        foreach ($rows as $row) {
            $nodes[(int) $row['id']] = [
                'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'name' => $row['name'],
                'enabled' => (int) $row['status'] === Node::STATUS_ENABLED,
            ];
        }

        return $nodes;
    }
}
