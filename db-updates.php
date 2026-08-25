<?php

use function MapasCulturais\__exec;
use function MapasCulturais\__table_exists;

// Chaves prefixadas: o core acumula os arquivos com `+=`, e chave repetida some sem erro.
return [
    'mdc: cria a tabela da árvore de atuação' => function () {
        if (__table_exists('mdc_tree_node')) {
            return;
        }

        __exec('
            CREATE TABLE mdc_tree_node (
                id SERIAL NOT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                level SMALLINT NOT NULL,
                display_order INT NOT NULL,
                status SMALLINT NOT NULL,

                PRIMARY KEY(id)
            )');

        __exec('
            ALTER TABLE mdc_tree_node
                ADD CONSTRAINT FK_mdc_tree_node_parent
                FOREIGN KEY (parent_id) REFERENCES mdc_tree_node(id) ON DELETE CASCADE');

        __exec('CREATE INDEX IDX_mdc_tree_node_parent_order ON mdc_tree_node (parent_id, display_order)');

        // O índice de irmãos não alcança a raiz: no Postgres nulo não é igual a nulo.
        __exec('CREATE UNIQUE INDEX UNQ_mdc_tree_node_sibling ON mdc_tree_node (parent_id, name) WHERE status > 0');
        __exec('CREATE UNIQUE INDEX UNQ_mdc_tree_node_root ON mdc_tree_node (name) WHERE parent_id IS NULL AND status > 0');
    },

];
