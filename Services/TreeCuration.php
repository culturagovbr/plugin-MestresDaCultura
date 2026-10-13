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

        if ($parent) {
            $this->refuseDisabled($parent, i::__('"%s" está desativado: reative antes de adicionar itens abaixo dele.'));
        }

        if ($parent && $parent->level >= self::MAX_LEVEL) {
            throw new CurationRefused(i::__('Atividade é o último nível da árvore: não há itens abaixo dela.'));
        }

        $this->refuseSiblingWithTheSameName($parent, $name);

        $node = new Node;
        $node->parent = $parent;
        $node->name = $name;
        // Entra no fim da lista.
        $node->displayOrder = $this->nextOrderUnder($parent);
        $node->save(true);

        return $node;
    }

    function rename(Node $node, string $name): Node
    {
        $name = $this->cleanName($name);

        $this->refuseDisabled($node, i::__('"%s" está desativado: reative antes de renomear.'));

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

        $this->refuseDisabled($node, i::__('"%s" está desativado: reative antes de mudar a ordem.'));

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

    /**
     * Leva o item, com tudo o que está abaixo dele, para o fim da lista de outro pai (null: vira segmento).
     */
    function move(Node $node, ?Node $parent): void
    {
        $this->refuseDisabled($node, i::__('"%s" está desativado: reative antes de mover.'));

        if ($parent === $node->parent) {
            throw new CurationRefused(sprintf(i::__('"%s" já está nesse lugar.'), $node->name));
        }

        $descendant_ids = App::i()->repo(Node::class)->findDescendantIds($node->id);

        if ($parent && ($parent === $node || in_array($parent->id, $descendant_ids, true))) {
            throw new CurationRefused(i::__('Um item não pode ir para dentro do próprio ramo.'));
        }

        if ($parent && $parent->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" está desativado: reative antes de mover itens para ele.'), $parent->name));
        }

        // O ramo inteiro conta, inclusive o que está desativado e pode voltar.
        $height = $this->deepestLevel($descendant_ids, $node->level) - $node->level;

        if (($parent ? $parent->level : 0) + 1 + $height > self::MAX_LEVEL) {
            throw new CurationRefused(i::__('O ramo não cabe nesse lugar: a árvore tem no máximo 4 níveis.'));
        }

        $this->refuseSiblingWithTheSameName($parent, $node->name, $node);

        $node->displayOrder = $this->nextOrderUnder($parent);
        $node->parent = $parent;
        $node->save(true);
    }

    /**
     * Tira o item da lista do formulário, com tudo o que está abaixo dele, sem apagar nada.
     */
    function disable(Node $node): void
    {
        $this->refuseDisabled($node, i::__('"%s" já está desativado.'));

        $repository = App::i()->repo(Node::class);
        $branch = [$node, ...array_map(fn (int $id) => $repository->find($id), $repository->findDescendantIds($node->id))];

        foreach ($branch as $item) {
            if ($item->status === Node::STATUS_ENABLED) {
                $item->status = Node::STATUS_DISABLED;
                $item->save();
            }
        }

        App::i()->em->flush();
    }

    /**
     * Devolve só o item à lista; o que estava abaixo dele volta um a um, se precisar.
     */
    function enable(Node $node): void
    {
        if ($node->status === Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" já está ativo.'), $node->name));
        }

        if ($node->parent && $node->parent->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf(i::__('"%s" está desativado: reative primeiro o item acima.'), $node->parent->name));
        }

        $this->refuseSiblingWithTheSameName($node->parent, $node->name, $node);

        $node->status = Node::STATUS_ENABLED;
        $node->save(true);
    }

    private function refuseDisabled(Node $node, string $message): void
    {
        if ($node->status !== Node::STATUS_ENABLED) {
            throw new CurationRefused(sprintf($message, $node->name));
        }
    }

    private function nextOrderUnder(?Node $parent): int
    {
        $siblings = $this->siblingsOf($parent);

        return $siblings ? max(array_map(fn (Node $sibling) => $sibling->displayOrder, $siblings)) + 1 : 1;
    }

    /** @param int[] $ids */
    private function deepestLevel(array $ids, int $default): int
    {
        $repository = App::i()->repo(Node::class);

        return array_reduce($ids, fn (int $deepest, int $id) => max($deepest, $repository->find($id)->level), $default);
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
            if ($sibling !== $except && $sibling->status === Node::STATUS_ENABLED && TreeText::comparable($sibling->name) === TreeText::comparable($name)) {
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
}
