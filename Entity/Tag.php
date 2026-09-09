<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\Entity]
#[ORM\Table(name: 'pim_tag')]
#[PIM\Config(labelProperty: 'title', sortBy: 'title', sortOrder: 'ASC')]
class Tag extends Base
{

    #[ORM\Column(type: 'string', unique: true)]
    #[PIM\Config(unique: true)]
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
