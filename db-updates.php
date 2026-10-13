<?php

use MapasCulturais\App;
use MDC\Entities\Node;

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

    'mdc: registra o nó da árvore no enum object_type' => function () {
        __exec('
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_enum
                    WHERE enumlabel = \'MDC\Entities\Node\'
                      AND enumtypid = (SELECT oid FROM pg_type WHERE typname = \'object_type\')
                ) THEN
                    ALTER TYPE object_type ADD VALUE \'MDC\Entities\Node\';
                END IF;
            END $$;
        ');
    },

    // Cria cada item pela entidade, com o nível e a revisão de criação.
    'mdc: carrega a árvore de atuação' => function () {
        $conn = App::i()->em->getConnection();

        // Só carrega com a tabela vazia.
        if ($conn->fetchOne('SELECT 1 FROM mdc_tree_node LIMIT 1')) {
            return;
        }

        $tree = json_decode(file_get_contents(__DIR__ . '/data/atuacao.json'), true, flags: JSON_THROW_ON_ERROR);

        $create = function (array $items, ?Node $parent) use (&$create) {
            foreach ($items as $position => $item) {
                $node = new Node;
                $node->parent = $parent;
                $node->name = $item['name'];
                $node->displayOrder = $position + 1;
                $node->save(true);

                $create($item['children'] ?? [], $node);
            }
        };

        // Carrega a árvore inteira numa transação.
        $conn->beginTransaction();

        try {
            $create($tree, null);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    },

    // "Item equivale a referência": vale para os relatórios, nunca altera as respostas.
    'mdc: cria a tabela de equivalências da árvore' => function () {
        if (__table_exists('mdc_tree_equivalence')) {
            return;
        }

        __exec('
            CREATE TABLE mdc_tree_equivalence (
                node_id INT NOT NULL,
                equivalent_to INT NOT NULL,
                user_id INT DEFAULT NULL,
                create_timestamp TIMESTAMP NOT NULL DEFAULT NOW(),

                PRIMARY KEY(node_id),
                CONSTRAINT CHK_mdc_tree_equivalence_self CHECK (node_id <> equivalent_to)
            )');

        __exec('
            ALTER TABLE mdc_tree_equivalence
                ADD CONSTRAINT FK_mdc_tree_equivalence_node
                FOREIGN KEY (node_id) REFERENCES mdc_tree_node(id) ON DELETE CASCADE');

        __exec('
            ALTER TABLE mdc_tree_equivalence
                ADD CONSTRAINT FK_mdc_tree_equivalence_target
                FOREIGN KEY (equivalent_to) REFERENCES mdc_tree_node(id) ON DELETE CASCADE');

        __exec('
            ALTER TABLE mdc_tree_equivalence
                ADD CONSTRAINT FK_mdc_tree_equivalence_user
                FOREIGN KEY (user_id) REFERENCES usr(id) ON DELETE SET NULL');

        __exec('CREATE INDEX IDX_mdc_tree_equivalence_target ON mdc_tree_equivalence (equivalent_to)');
    },

    // Decisões da fila "Não encontrou?": o que a curadoria fez com cada texto de "Outro", agrupado pelo
    // lugar onde foi escrito. As respostas não mudam.
    'mdc: cria a tabela de decisões da fila "Não encontrou?"' => function () {
        if (__table_exists('mdc_tree_other_review')) {
            return;
        }

        __exec("
            CREATE TABLE mdc_tree_other_review (
                id SERIAL NOT NULL,
                parent_id INT DEFAULT NULL,
                text_key VARCHAR(255) NOT NULL,
                decision VARCHAR(16) NOT NULL,
                node_id INT DEFAULT NULL,
                note TEXT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                create_timestamp TIMESTAMP NOT NULL DEFAULT NOW(),

                PRIMARY KEY(id),
                CONSTRAINT CHK_mdc_tree_other_review_decision CHECK (decision IN ('created', 'associated', 'archived'))
            )");

        __exec('
            ALTER TABLE mdc_tree_other_review
                ADD CONSTRAINT FK_mdc_tree_other_review_parent
                FOREIGN KEY (parent_id) REFERENCES mdc_tree_node(id) ON DELETE CASCADE');

        __exec('
            ALTER TABLE mdc_tree_other_review
                ADD CONSTRAINT FK_mdc_tree_other_review_node
                FOREIGN KEY (node_id) REFERENCES mdc_tree_node(id) ON DELETE SET NULL');

        __exec('
            ALTER TABLE mdc_tree_other_review
                ADD CONSTRAINT FK_mdc_tree_other_review_user
                FOREIGN KEY (user_id) REFERENCES usr(id) ON DELETE SET NULL');

        // Uma decisão por texto em cada lugar; o "geral" (sem pai) entra como 0.
        __exec('CREATE UNIQUE INDEX UNQ_mdc_tree_other_review ON mdc_tree_other_review (COALESCE(parent_id, 0), text_key)');
    },
];
