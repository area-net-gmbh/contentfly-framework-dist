<?php
/**
 * Created by PhpStorm.
 * User: ms
 * Date: 13.07.16
 * Time: 09:34
 */

namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 * @ORM\Entity
 * @ORM\Table(name="pim_folder")
 * @PIM\Config(labelProperty="title", excludeFromSync=true)
 *
 * excludeFromSync: Ordner sind Struktur der Dateiablage, keine Nutzdaten; sie kommen mit den Dateien.
 * Mit 000-000-0013 aus der fest verdrahteten Liste in Api.php hierher geholt —
 * eine Ausschlussliste, die in keiner Annotation steht, kann ein Projekt nicht sehen.
 */

class Folder extends BaseTree
{
    use \Custom\Traits\Folder;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $title;

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
    
    
}