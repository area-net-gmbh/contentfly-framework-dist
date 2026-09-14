<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 *
 * excludeFromSync: Navigation structure of the deleted user interface.
 * Moved here with 000-000-0013 from the hard-wired list in Api.php —
 * an exclusion list that is not written in any annotation cannot be seen by a project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_nav')]
#[PIM\Config(labelProperty: 'title', excludeFromSync: true)]
class Nav extends BaseSortable
{

    #[ORM\Column(type: 'string', nullable: false)]
    protected $title;

    #[ORM\Column(type: 'string', nullable: true)]
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
