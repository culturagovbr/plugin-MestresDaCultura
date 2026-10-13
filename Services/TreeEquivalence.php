<?php

namespace MDC\Services;

use MapasCulturais\App;
use MapasCulturais\i;
use MDC\Entities\Node;
use MDC\Exceptions\CurationRefused;

/**
 * Equivalências da Árvore de Atuação: "este item equivale àquele", para os relatórios contarem os dois como um só,
 * sem alterar as respostas. Sem cadeias: uma referência não aponta para outra.
 */
class TreeEquivalence
{
    /** @return array<int,int> item => referência */
    function all(): array
    {
        return array_map('intval', App::i()->em->getConnection()->fetchAllKeyValue('SELECT node_id, equivalent_to FROM mdc_tree_equivalence'));
    }

    function mark(Node $node, Node $reference): void
    {
        Curators::require($node);

        $map = $this->all();
        $repository = App::i()->repo(Node::class);

        if ($node->id === $reference->id) {
            throw new CurationRefused(i::__('Um item não equivale a ele mesmo.'));
        }

        if (isset($map[$node->id])) {
            throw new CurationRefused(sprintf(i::__('"%s" já equivale a "%s": desfaça antes de marcar outra.'), $node->name, $repository->find($map[$node->id])->name));
        }

        if (isset($map[$reference->id])) {
            $final = $repository->find($map[$reference->id]);

            throw new CurationRefused(sprintf(i::__('"%s" já equivale a "%s": marque a equivalência direto com "%s".'), $reference->name, $final->name, $final->name));
        }

        if (in_array($node->id, $map, true)) {
            throw new CurationRefused(sprintf(i::__('Outros itens equivalem a "%s": ele é referência e não pode equivaler a outro.'), $node->name));
        }

        App::i()->em->getConnection()->insert('mdc_tree_equivalence', [
            'node_id' => $node->id,
            'equivalent_to' => $reference->id,
            'user_id' => App::i()->user->id,
        ]);
    }

    function undo(Node $node): void
    {
        Curators::require($node);

        if (!isset($this->all()[$node->id])) {
            throw new CurationRefused(sprintf(i::__('"%s" não equivale a nenhum item.'), $node->name));
        }

        App::i()->em->getConnection()->delete('mdc_tree_equivalence', ['node_id' => $node->id]);
    }
}
