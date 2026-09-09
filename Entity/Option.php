<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\Entity]
#[ORM\Table(name: 'pim_option')]
#[PIM\Config(labelProperty: 'value', sortBy: 'sorting', sortOrder: 'ASC', sortRestrictTo: 'group')]
class Option extends BaseSortable
{

    #[ORM\Column(type: 'string', nullable: false)]
    protected $value;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\OptionGroup')]
    #[ORM\JoinColumn(onDelete: 'CASCADE', nullable: false)]
    #[PIM\Config(isFilterable: true)]
    protected $group;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id): void
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return mixed
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @param mixed $group
     */
    public function setGroup($group): void
    {
        $this->group = $group;
    }

}



