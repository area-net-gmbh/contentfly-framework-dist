<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 *
 * excludeFromSync: permission management, like PIM\Group.
 * Moved here from the hard-wired list in Api.php with 000-000-0013 —
 * an exclusion list that is not written in any annotation cannot be seen by a project.
 */
#[ORM\Entity]
#[PIM\Config(excludeFromSync: true)]
#[ORM\Table(name: 'pim_permission')]
class Permission extends Base
{
    const NONE    = 0;
    const OWN     = 1;
    const GROUP   = 3;
    const ALL     = 2;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\Group', inversedBy: 'permissions')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id')]
    protected $group;

    #[ORM\Column(type: 'string')]
    protected $entityName;

    #[ORM\Column(type: 'integer')]
    protected $readable;

    #[ORM\Column(type: 'integer')]
    protected $writable;

    #[ORM\Column(type: 'integer')]
    protected $deletable;

    #[ORM\Column(type: 'integer')]
    protected $export = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    protected $extended;

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



    /**
     * @return mixed
     */
    public function getEntityName()
    {
        return $this->entityName;
    }

    /**
     * @param mixed $entityName
     */
    public function setEntityName($entityName): void
    {
        $this->entityName = $entityName;
    }

    /**
     * @return mixed
     */
    public function getReadable()
    {
        return $this->readable;
    }

    /**
     * @param mixed $readable
     */
    public function setReadable($readable): void
    {
        $this->readable = $readable;
    }

    /**
     * @return mixed
     */
    public function getWritable()
    {
        return $this->writable;
    }

    /**
     * @param mixed $writable
     */
    public function setWritable($writable): void
    {
        $this->writable = $writable;
    }

    /**
     * @return mixed
     */
    public function getDeletable()
    {
        return $this->deletable;
    }

    /**
     * @param mixed $deletable
     */
    public function setDeletable($deletable): void
    {
        $this->deletable = $deletable;
    }

    /**
     * @return mixed
     */
    public function getExtended()
    {
        return $this->extended;
    }

    /**
     * @param mixed $extended
     */
    public function setExtended($extended): void
    {
        $this->extended = $extended;
    }

    /**
     * @return mixed
     */
    public function getExport()
    {
        return $this->export;
    }

    /**
     * @param mixed $export
     */
    public function setExport($export): void
    {
        $this->export = $export;
    }


    





}