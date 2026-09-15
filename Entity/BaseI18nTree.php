<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\Table(name: 'pim_i18n_tree')]

class BaseI18nTree extends BaseI18nSortable
{
    /*
     * A PARENT NODE IN THE SAME LANGUAGE — TWO JOIN COLUMNS (000-000-0025).
     *
     * The key of this class is composite: `id` from `Entity\Base` and `lang` from `BaseI18n`.
     * Doctrine requires one join column per key column; this relation had only `parent_id`, and
     * `orm:validate-schema` reported it:
     *
     *     The join columns of the association 'treeParent' have to match to ALL identifier
     *     columns of the target entity 'Areanet\PIM\Entity\BaseI18nTree', however 'id, lang'
     *     are missing.
     *
     * The second column is `parent_lang`, and it holds the language of the child. That is not an
     * invention for this class; the framework has always read it that way:
     *
     *   - `JoinType::toDatabase()` references an i18n target by `id` AND the language of the
     *     object being written.
     *   - `Api::getTree()` joins `treeParent` with `parent.lang = :lang` — the language of the
     *     children it lists.
     *   - `Api::getTree2()` joins `pim_i18n_tree` on `t.lang = e.lang` and builds the hierarchy
     *     within that one language.
     *
     * Its own `lang` column cannot double as the join column: it is an identifier field, and
     * Doctrine rejects a column mapped twice. Decision and the alternative that was rejected
     * (dropping the class): an_project/docs/architecture.md, Key decisions, 2026-09-15.
     */
    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\BaseI18nTree', inversedBy: 'treeChilds')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    #[ORM\JoinColumn(name: 'parent_lang', referencedColumnName: 'lang', onDelete: 'SET NULL')]
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
     * The second error of the same message — the missing join column for `lang` — was handed on
     * as a task of its own and is resolved with 000-000-0025, see `$treeParent` above.
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