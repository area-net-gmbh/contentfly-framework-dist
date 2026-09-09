<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 * @ORM\Entity
 * @ORM\Table(name="pim_nav")
 * @PIM\Config(labelProperty="title", excludeFromSync=true)
 *
 * excludeFromSync: Navigationsstruktur der geloeschten Oberflaeche.
 * Mit 000-000-0013 aus der fest verdrahteten Liste in Api.php hierher geholt —
 * eine Ausschlussliste, die in keiner Annotation steht, kann ein Projekt nicht sehen.
 */
class Nav extends BaseSortable
{

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    protected $title;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $icon;

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param mixed $title
     */
    public function setTitle($title): void
    {
        $this->title = $title;
    }

    /**
     * @return mixed
     */
    public function getIcon()
    {
        return $this->icon;
    }

    /**
     * @param mixed $icon
     */
    public function setIcon($icon): void
    {
        $this->icon = $icon;
    }




}
