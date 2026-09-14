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
 *
 * excludeFromSync: folders are structure of the file storage, not payload data; they come with the files.
 * Moved here from the hard-wired list in Api.php with 000-000-0013 —
 * an exclusion list that is not written in any annotation cannot be seen by a project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_folder')]
#[PIM\Config(labelProperty: 'title', excludeFromSync: true)]

class Folder extends BaseTree
{
    use \Custom\Traits\Folder;

    #[ORM\Column(type: 'string', nullable: true)]
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