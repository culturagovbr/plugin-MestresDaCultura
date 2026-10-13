<?php

namespace MDC\Entities;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\App;
use MapasCulturais\Traits;

/**
 * @property int $id
 * @property string $name
 * @property int $level Profundidade na árvore, derivada do pai
 * @property int $displayOrder Ordem entre os irmãos, definida pela curadoria
 * @property int $status
 * @property \MDC\Entities\Node|null $parent
 * @property-read \MDC\Entities\Node[] $children
 *
 * @ORM\Table(name="mdc_tree_node", indexes={
 *      @ORM\Index(name="IDX_mdc_tree_node_parent_order", columns={"parent_id", "display_order"})
 * })
 * @ORM\Entity(repositoryClass="MDC\Repositories\Node")
 */
class Node extends \MapasCulturais\Entity
{
    use Traits\EntityNested,
        Traits\EntitySoftDelete,
        Traits\EntityRevision;

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="mdc_tree_node_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var \MDC\Entities\Node|null
     *
     * @ORM\ManyToOne(targetEntity="MDC\Entities\Node")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="parent_id", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     * })
     */
    protected $parent;

    /**
     * @var \MDC\Entities\Node[]
     *
     * @ORM\OneToMany(targetEntity="MDC\Entities\Node", mappedBy="parent", fetch="LAZY")
     * @ORM\OrderBy({"displayOrder" = "ASC"})
     */
    protected $_children;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=255, nullable=false)
     */
    protected $name;

    /**
     * @var integer
     *
     * @ORM\Column(name="level", type="smallint", nullable=false)
     */
    protected $level = 1;

    /**
     * @var integer
     *
     * @ORM\Column(name="display_order", type="integer", nullable=false)
     */
    protected $displayOrder = 1;

    /**
     * @var integer
     *
     * @ORM\Column(name="status", type="smallint", nullable=false)
     */
    protected $status = self::STATUS_ENABLED;

    // Lê o nível carregando o item, mesmo quando é um proxy do Doctrine.
    function getLevel(): int
    {
        return $this->level;
    }

    function getChildren(): array
    {
        return $this->_children ? $this->_children->toArray() : [];
    }

    function save($flush = false)
    {
        // `parent` só recebe o pai novo mais adiante, dentro do save.
        $parent = $this->getParent();
        $previous_level = $this->level;

        $this->level = $parent ? $parent->getLevel() + 1 : 1;

        parent::save($flush);

        if ($this->id && $this->level !== $previous_level) {
            $this->shiftDescendantLevels($this->level - $previous_level);
        }
    }

    // Ajusta direto no banco o nível dos descendentes do item movido, sem uma revisão por descendente.
    private function shiftDescendantLevels(int $delta): void
    {
        $app = App::i();
        $ids = $app->repo(self::class)->findDescendantIds($this->id);

        if (!$ids) {
            return;
        }

        $app->em->getConnection()->executeStatement(
            'UPDATE mdc_tree_node SET level = level + ? WHERE id IN (?)',
            [$delta, $ids],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        );

        // Os já carregados nesta requisição passam a refletir o banco.
        $unit_of_work = $app->em->getUnitOfWork();

        foreach ($ids as $id) {
            if ($node = $unit_of_work->tryGetById($id, self::class)) {
                $app->em->refresh($node);
            }
        }
    }

    // Sem dono, a verificação genérica compara o usuário com ele mesmo e libera qualquer um.
    protected function canUserCreate($user)
    {
        return $user->is('admin');
    }

    protected function canUserCreateChild($user)
    {
        return $user->is('admin');
    }

    protected function canUserModify($user)
    {
        return $user->is('admin');
    }

    protected function canUserRemove($user)
    {
        return $user->is('admin');
    }

}
