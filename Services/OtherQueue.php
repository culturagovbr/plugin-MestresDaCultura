<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;
use MDC\Dtos\OtherEntry;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;

/**
 * Fila "Não encontrou?": os textos de "Outro" das respostas da atuação, agrupados pelo lugar onde foram
 * escritos e pelo texto sem caixa, acento e espaços repetidos. A curadoria cria o item, associa a um que
 * já existe ou arquiva; as respostas continuam como foram dadas.
 */
class OtherQueue
{
    private const MAX_SUGGESTIONS = 3;

    const PENDING = 'pending';
    const DECIDED = 'decided';
    const DECISIONS = ['created', 'associated', 'archived'];

    /**
     * Uma página da fila: os pendentes (mais citados antes) ou os já tratados (os mais recentes antes),
     * com a busca e o filtro por decisão. As sugestões são calculadas só para os pendentes da página.
     *
     * @return array{entries: OtherEntry[], count: int, counts: array<string,int>, totals: array<string,int>}
     */
    function page(string $status, string $keyword = '', ?string $decision = null, int $page = 1, int $limit = 20): array
    {
        $result = self::slice($this->groupsWithDecisions(), App::i()->repo(Node::class)->findPaths(), $status, $keyword, $decision, $page, $limit);

        if ($status === self::PENDING && $result['entries']) {
            $nodes = array_map(
                fn (Node $node) => ['id' => $node->id, 'name' => $node->name, 'parent' => $node->parent?->id],
                App::i()->repo(Node::class)->findBy(['status' => Node::STATUS_ENABLED])
            );
            $result['entries'] = array_map(fn (OtherEntry $entry) => $entry->withSuggestions(self::suggest($entry->key, $entry->parent, $nodes)), $result['entries']);
        }

        return $result;
    }

    /**
     * Filtra, ordena e corta a fila. A busca olha o texto, o lugar onde foi escrito e, nos tratados, o item
     * escolhido e o motivo; as contagens por decisão já têm a busca aplicada, e os totais não têm filtro.
     *
     * @param OtherEntry[] $entries
     * @param array<int,string> $paths id do item => caminho ("Segmento › Tipologia")
     * @return array{entries: OtherEntry[], count: int, counts: array<string,int>, totals: array<string,int>}
     */
    static function slice(array $entries, array $paths, string $status, string $keyword, ?string $decision, int $page, int $limit): array
    {
        $decided = array_filter($entries, fn (OtherEntry $entry) => $entry->decision !== null);
        $totals = [self::PENDING => count($entries) - count($decided), self::DECIDED => count($decided)];
        $list = $status === self::DECIDED ? $decided : array_diff_key($entries, $decided);

        $query = TreeText::comparable($keyword);

        if ($query !== '') {
            $list = array_filter($list, function (OtherEntry $entry) use ($paths, $query) {
                $fields = [$entry->text, $paths[$entry->parent] ?? '', $paths[$entry->decision['node'] ?? 0] ?? '', $entry->decision['note'] ?? ''];

                return (bool) array_filter($fields, fn ($field) => str_contains(TreeText::comparable((string) $field), $query));
            });
        }

        $counts = array_fill_keys(self::DECISIONS, 0);

        foreach ($list as $entry) {
            if ($entry->decision) {
                $counts[$entry->decision['type']]++;
            }
        }

        if ($decision !== null) {
            $list = array_filter($list, fn (OtherEntry $entry) => $entry->decision['type'] === $decision);
        }

        usort($list, $status === self::DECIDED
            ? fn (OtherEntry $a, OtherEntry $b) => [$b->decision['at'], $a->text] <=> [$a->decision['at'], $b->text]
            : fn (OtherEntry $a, OtherEntry $b) => [-($a->sent + $a->drafts), $a->text] <=> [-($b->sent + $b->drafts), $b->text]);

        return [
            'entries' => array_slice($list, ($page - 1) * $limit, $limit),
            'count' => count($list),
            'counts' => $counts,
            'totals' => $totals,
        ];
    }

    /** @return OtherEntry[] os grupos de texto, com a decisão de cada um, sem sugestões */
    private function groupsWithDecisions(): array
    {
        $decisions = $this->decisions();

        return array_map(fn (array $group) => new OtherEntry(
            $group['parent'],
            $group['key'],
            $group['text'],
            $group['sent'],
            $group['drafts'],
            [],
            $decisions[self::place($group['parent'], $group['key'])] ?? null,
        ), self::group($this->answeredOthers()));
    }

    /** Cria o item com o texto (que a curadoria pode corrigir) no lugar escolhido, por padrão onde foi escrito. */
    function create(?Node $parent, string $key, string $name, ?Node $place): Node
    {
        $this->refuseDecided($parent, $key);
        $node = (new TreeCuration())->add($place, $name);
        $this->decide($parent, $key, 'created', $node);

        return $node;
    }

    function associate(?Node $parent, string $key, Node $node): void
    {
        $this->refuseDecided($parent, $key);
        Curators::require($node);
        $this->decide($parent, $key, 'associated', $node);
    }

    function archive(?Node $parent, string $key, string $note = ''): void
    {
        $this->refuseDecided($parent, $key);
        Curators::require($parent);
        $this->decide($parent, $key, 'archived', null, trim($note) ?: null);
    }

    /**
     * Agrupa os "Outro" das respostas: mesmo lugar e mesmo texto comparável são um grupo, contado por
     * cadastro, com a grafia mais usada.
     *
     * @param array<array{registration: int, status: int, parent: ?int, text: string}> $rows
     * @return array<array{parent: ?int, key: string, text: string, sent: int, drafts: int}>
     */
    static function group(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = TreeText::comparable($row['text']);

            if ($key === '') {
                continue;
            }

            $group = &$groups[self::place($row['parent'], $key)];
            $group['parent'] = $row['parent'];
            $group['key'] = $key;
            $group['spellings'][trim(preg_replace('/\s+/u', ' ', $row['text']))][] = true;
            $group[$row['status'] > Registration::STATUS_DRAFT ? 'sent' : 'drafts'][$row['registration']] = true;
            unset($group);
        }

        return array_values(array_map(function (array $group) {
            $spellings = array_map('count', $group['spellings']);
            arsort($spellings);

            return [
                'parent' => $group['parent'],
                'key' => $group['key'],
                'text' => (string) array_key_first($spellings),
                'sent' => count($group['sent'] ?? []),
                'drafts' => count($group['drafts'] ?? []),
            ];
        }, $groups));
    }

    /**
     * Itens ativos parecidos com o texto: o nome inteiro (igual, contido ou até 2 letras de diferença) e, depois,
     * uma palavra de 5 letras ou mais em comum ou quase. Os do lugar onde o texto foi escrito vêm antes; o próprio
     * lugar fica de fora.
     *
     * @param array<array{id: int, name: string, parent: ?int}> $nodes
     * @return int[]
     */
    static function suggest(string $key, ?int $parent, array $nodes): array
    {
        $words = self::longWords($key);
        $scored = [];

        foreach ($nodes as $node) {
            if ($node['id'] === $parent) {
                continue;
            }

            $name = TreeText::comparable($node['name']);
            $distance = levenshtein($key, $name);
            $contains = mb_strlen($key) >= 4 && mb_strlen($name) >= 4 && (str_contains($name, $key) || str_contains($key, $name));
            $word = self::closestWord($words, self::longWords($name));

            if ($distance <= 2 || $contains) {
                $scored[] = ['id' => $node['id'], 'score' => [$node['parent'] === $parent ? 0 : 1, 0, $distance]];
            } elseif ($word === 0) {
                $scored[] = ['id' => $node['id'], 'score' => [$node['parent'] === $parent ? 0 : 1, 1, 0]];
            }
        }

        usort($scored, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_slice(array_column($scored, 'id'), 0, self::MAX_SUGGESTIONS);
    }

    /** @return string[] */
    private static function longWords(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $text), fn ($word) => strlen($word) >= 5));
    }

    /**
     * Menor diferença entre uma palavra de um e uma do outro, já descontada a tolerância pelo tamanho:
     * 0 quando há palavra em comum ou quase; PHP_INT_MAX quando não há.
     */
    private static function closestWord(array $words, array $others): int
    {
        $closest = PHP_INT_MAX;

        foreach ($words as $word) {
            foreach ($others as $other) {
                $tolerance = min(strlen($word), strlen($other)) >= 8 ? 2 : 1;
                $distance = levenshtein($word, $other);

                if ($distance <= $tolerance) {
                    $closest = min($closest, $distance);
                }
            }
        }

        return $closest === PHP_INT_MAX ? PHP_INT_MAX : 0;
    }

    private static function place(?int $parent, string $key): string
    {
        return ($parent ?? 0) . '|' . $key;
    }

    /** @return array<array{registration: int, status: int, parent: ?int, text: string}> */
    private function answeredOthers(): array
    {
        $rows = App::i()->em->getConnection()->fetchAllAssociative(
            "SELECT r.id AS registration, r.status, (other->>'parent')::int AS parent, other->>'text' AS text
               FROM registration_meta m
               JOIN registration r ON r.id = m.object_id
               JOIN registration_field_configuration f ON m.key = 'field_' || f.id AND f.field_type = :type
              CROSS JOIN LATERAL jsonb_array_elements(m.value::jsonb -> 'others') AS other
              WHERE m.value LIKE '{%' AND r.status >= :draft",
            ['type' => ActingField::SLUG, 'draft' => Registration::STATUS_DRAFT]
        );

        return array_map(fn (array $row) => [
            'registration' => (int) $row['registration'],
            'status' => (int) $row['status'],
            'parent' => $row['parent'] === null ? null : (int) $row['parent'],
            'text' => (string) $row['text'],
        ], $rows);
    }

    /** @return array<string,array{type: string, node: ?int, note: ?string, at: string}> lugar|texto => decisão */
    private function decisions(): array
    {
        $decisions = [];

        foreach (App::i()->em->getConnection()->fetchAllAssociative('SELECT parent_id, text_key, decision, node_id, note, create_timestamp FROM mdc_tree_other_review') as $row) {
            $decisions[self::place($row['parent_id'] === null ? null : (int) $row['parent_id'], $row['text_key'])] = [
                'type' => $row['decision'],
                'node' => $row['node_id'] === null ? null : (int) $row['node_id'],
                'note' => $row['note'],
                'at' => $row['create_timestamp'],
            ];
        }

        return $decisions;
    }

    private function refuseDecided(?Node $parent, string $key): void
    {
        if (isset($this->decisions()[self::place($parent?->id, TreeText::comparable($key))])) {
            throw new CurationRefused(i::__('Esse texto já foi tratado.'));
        }
    }

    private function decide(?Node $parent, string $key, string $decision, ?Node $node, ?string $note = null): void
    {
        App::i()->em->getConnection()->insert('mdc_tree_other_review', [
            'parent_id' => $parent?->id,
            'text_key' => TreeText::comparable($key),
            'decision' => $decision,
            'node_id' => $node?->id,
            'note' => $note,
            'user_id' => App::i()->user->id,
        ]);
    }
}
