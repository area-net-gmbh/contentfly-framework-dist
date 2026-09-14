<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\Table(name: 'pim_i18n_tree')]

class BaseI18nTree extends BaseI18nSortable
{
    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\BaseI18nTree', inversedBy: 'treeChilds')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    #[PIM\Config(isFilterable: true, i18n_universal: true)]
    protected $treeParent;

    /*
     * TARGET CLASS CORRECTED (010-005-0003).
     *
     * This used to say `targetEntity: 'Areanet\PIM\Entity\BaseTree'` — the neighbouring class
     * this one was copied from. `orm:validate-schema` called it out by name:
     *
     *     The association BaseI18nTree#treeParent refers to the inverse side
     *     BaseI18nTree#treeChilds which targets a different entity (BaseTree).
     *
     * THE SECOND ERROR OF THE SAME MESSAGE REMAINS OPEN: `treeParent` references `BaseI18nTree`
     * with ONE join column (`parent_id`), but its key consists of `id` AND `lang`. Doctrine
     * requires one join column per key column.
     *
     * It is NOT fixed here, and that is a decision: whether a child node is supposed to point to
     * a parent node of the SAME language is written down nowhere — and nobody extends this
     * class, so the intent cannot be checked against anything. Inventing a second join column
     * would mean defining semantics for unused code and changing the schema of `pim_i18n_tree`
     * in the process. The finding leaves Epic 010 as a task of its own.
     */
    #[ORM\OneToMany(targetEntity: 'Areanet\\PIM\\Entity\\BaseI18nTree', mappedBy: 'treeParent')]
    protected $treeChilds;

    /**
     * @return mixed
     */
    public function getTreeParent()
    {
        return $this->treeParent;
    }

    /**
     * @param mixed $treeParent
     */
    public function setTreeParent($treeParent): void
    {
        $this->treeParent = $treeParent;
    }

    /**
     * @return mixed
     */
    public function getTreeChilds()
    {
        return $this->treeChilds;
    }

    /**
     * @param mixed $treeChilds
     */
    public function setTreeChilds($treeChilds): void
    {
        $this->treeChilds = $treeChilds;
    }

}