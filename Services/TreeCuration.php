<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;

/**
 * Ações de curadoria da Árvore de Atuação, com as regras de conteúdo da árvore num só lugar. Quebrar uma regra lança
 * CurationRefused com a mensagem para a tela. Renomear só corrige grafia; mudança de sentido é item novo.
 */
class TreeCuration
{
    /** Segmento › Tipologia › Subtipologia › Atividade */
    const MAX_LEVEL = 4;

    private const NAME_MAX_LENGTH = 255;

    function add(?Node $parent, string $name): Node
    {
        $name = $this->cleanName($name);

        if ($parent && $parent->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" está desativado: reative antes de adicionar itens abaixo dele.'), $parent->name));
        }

        if ($parent && $parent->level >= self::MAX_LEVEL) {
            throw new CurationRefused(i::__('Atividade é o último nível da árvore: não há itens abaixo dela.'));
        }

        $this->refuseSiblingWithTheSameName($parent, $name);

        $siblings = $this->siblingsOf($parent);

        $node = new Node;
        $node->parent = $parent;
        $node->name = $name;
        // Entra no fim da lista.
        $node->displayOrder = $siblings ? max(array_map(fn (Node $sibling) => $sibling->displayOrder, $siblings)) + 1 : 1;
        $node->save(true);

        return $node;
    }

    function rename(Node $node, string $name): Node
    {
        $name = $this->cleanName($name);

        if ($node->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" está desativado: reative antes de renomear.'), $node->name));
        }

        if ($name === $node->name) {
            throw new CurationRefused(i::__('O nome não mudou.'));
        }

        $this->refuseSiblingWithTheSameName($node->parent, $name, $node);

        $node->name = $name;
        $node->save(true);

        return $node;
    }

    /**
     * Sobe (-1) ou desce (+1) uma posição entre os irmãos ativos, os que aparecem no formulário.
     */
    function reorder(Node $node, int $offset): void
    {
        if (!in_array($offset, [-1, 1], true)) {
            throw new \InvalidArgumentException('A ordem muda uma posição por vez: -1 sobe, +1 desce.');
        }

        if ($node->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" está desativado: reative antes de mudar a ordem.'), $node->name));
        }

        $siblings = array_values(array_filter($this->siblingsOf($node->parent), fn (Node $sibling) => $sibling->status === Node::STATUS_ENABLED));
        $position = array_search($node, $siblings, true);
        $neighbor = $siblings[$position + $offset] ?? null;

        if (!$neighbor) {
            throw new CurationRefused($offset < 0 ? i::__('Já é o primeiro da lista.') : i::__('Já é o último da lista.'));
        }

        // Com ordem repetida entre os dois, numera a lista antes da troca.
        if ($node->displayOrder === $neighbor->displayOrder) {
            $this->numberInOrder($siblings);
        }

        [$node->displayOrder, $neighbor->displayOrder] = [$neighbor->displayOrder, $node->displayOrder];
        $node->save();
        $neighbor->save(true);
    }

    /** @return Node[] irmãos na ordem da lista, inclusive desativados */
    private function siblingsOf(?Node $parent): array
    {
        return App::i()->repo(Node::class)->findBy(['parent' => $parent], ['displayOrder' => 'ASC', 'id' => 'ASC']);
    }

    /** @param Node[] $siblings */
    private function numberInOrder(array $siblings): void
    {
        foreach ($siblings as $position => $sibling) {
            $sibling->displayOrder = $position + 1;
            $sibling->save();
        }
    }

    // Recusa irmão ativo de mesmo nome, sem contar caixa e acento ("Coco de Roda" e "Côco de roda").
    private function refuseSiblingWithTheSameName(?Node $parent, string $name, ?Node $except = null): void
    {
        foreach ($this->siblingsOf($parent) as $sibling) {
            if ($sibling !== $except && $sibling->status === Node::STATUS_ENABLED && $this->comparable($sibling->name) === $this->comparable($name)) {
                throw new CurationRefused(sprintf(i::__('Já existe "%s" neste mesmo lugar.'), $sibling->name));
            }
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw new CurationRefused(i::__('Escreva o nome.'));
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new CurationRefused(sprintf(i::__('O nome pode ter até %d caracteres.'), self::NAME_MAX_LENGTH));
        }

        return $name;
    }

    // Sem a extensão intl no ambiente: os acentos do português, à mão.
    private function comparable(string $name): string
    {
        return strtr(mb_strtolower($name), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
