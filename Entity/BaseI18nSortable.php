<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\MappedSuperclass]

class BaseI18nSortable extends BaseI18n
{
    #[ORM\Column(type: 'integer', options: ['default' => 0], nullable: true)]
    #[PIM\Config(i18n_universal: true)]
    protected $sorting = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true], nullable: true)]
    protected $isActive = 1;

    /**
     * @return mixed
     */
    public function getSorting()
    {
        return $this->sorting;
    }

    /**
     * @param mixed $sorting
     */
    public function setSorting($sorting): void
    {
        $this->sorting = $sorting;
    }

    /**
     * @return mixed
     */
    public function getIsActive()
    {
        return $this->isActive;
    }

    /**
     * @param mixed $isActive
     */
    public function setIsActive($isActive): void
    {
        $this->isActive = $isActive;
    }
    
}
