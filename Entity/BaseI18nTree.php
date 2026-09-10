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
     * ZIELKLASSE RICHTIGGESTELLT (010-005-0003).
     *
     * Hier stand `targetEntity: 'Areanet\PIM\Entity\BaseTree'` — die Klasse nebenan, aus der
     * diese hier kopiert wurde. `orm:validate-schema` nannte es beim Namen:
     *
     *     The association BaseI18nTree#treeParent refers to the inverse side
     *     BaseI18nTree#treeChilds which targets a different entity (BaseTree).
     *
     * OFFEN BLEIBT DER ZWEITE FEHLER DERSELBEN MELDUNG: `treeParent` verweist mit EINER
     * Join-Spalte (`parent_id`) auf `BaseI18nTree`, deren Schluessel aber aus `id` UND `lang`
     * besteht. Doctrine verlangt eine Join-Spalte je Schluesselspalte.
     *
     * Er wird hier NICHT behoben, und das ist eine Entscheidung: Ob ein Kindknoten auf einen
     * Elternknoten DERSELBEN Sprache zeigen soll, steht nirgends — und niemand erbt von dieser
     * Klasse, die Absicht ist also an nichts zu pruefen. Eine zweite Join-Spalte zu erfinden
     * hiesse, Semantik fuer unbenutzten Code festzulegen und dabei das Schema von
     * `pim_i18n_tree` zu aendern. Der Befund verlaesst Epic 010 als eigener Task.
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