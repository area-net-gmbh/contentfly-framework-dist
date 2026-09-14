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
#[ORM\Table(name: 'pim_navItem')]
#[PIM\Config(sortRestrictTo: 'nav', excludeFromSync: true)]
class NavItem extends BaseSortable
{

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\Nav')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[PIM\Config(isFilterable: true)]
    protected $nav;

    #[ORM\Column(type: 'string', nullable: true)]
    protected $entity;

    #[ORM\Column(type: 'string', nullable: true)]
    protected $title;

    #[ORM\Column(type: 'string', nullable: true)]
    protected $uri;

    /**
     * @return mixed
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * @param mixed $entity
     */
    public function setEntity($entity): void
    {
        $this->entity = $entity;
    }

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
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * @param mixed $uri
     */
    public function setUri($uri): void
    {
        $this->uri = $uri;
    }

    /**
     * @return mixed
     */
    public function getNav()
    {
        return $this->nav;
    }

    /**
     * @param mixed $nav
     */
    public function setNav($nav): void
    {
        $this->nav = $nav;
    }









}
