<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\Entity]
#[ORM\Table(name: 'pim_file')]
#[ORM\UniqueConstraint(name: 'file_unique', columns: ['name', 'folder_id'])]
#[PIM\Config(labelProperty: 'name', sortBy: 'name', sortOrder: 'ASC')]

class File extends Base
{
    use \Custom\Traits\File;

    #[ORM\Column(type: 'string')]
    protected $name;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\Folder')]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    #[PIM\Config(isFilterable: true)]
    protected $folder;


    #[ORM\Column(type: 'string', nullable: true)]
    protected $alias;

    #[ORM\Column(type: 'string', nullable: true)]
    protected $title;

    #[ORM\Column(type: 'string', nullable: true)]
    protected $altText;

    #[ORM\Column(type: 'text', nullable: true)]
    protected $description;

    #[ORM\Column(type: 'string')]
    protected $type;

    #[ORM\Column(type: 'string')]
    protected $hash;

    #[ORM\Column(type: 'integer')]
    protected $size;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $width;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $height;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected $isHidden;

    #[ORM\ManyToMany(targetEntity: 'Areanet\\PIM\\Entity\\Tag')]
    #[ORM\JoinTable(name: 'pim_file_tags')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[PIM\Config(isFilterable: true)]
    protected $tags;

    /**
     * @return mixed
     */
    public function getFolder()
    {
        return $this->folder;
    }

    /**
     * @param mixed $folder
     */
    public function setFolder($folder): void
    {
        $this->folder = $folder;
    }


    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name): void
    {
        $this->name = $name;
    }

    /**
     * @return mixed
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @param mixed $width
     */
    public function setWidth($width): void
    {
        $this->width = $width;
    }

    /**
     * @return mixed
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * @param mixed $height
     */
    public function setHeight($height): void
    {
        $this->height = $height;
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
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param mixed $type
     */
    public function setType($type): void
    {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @param mixed $hash
     */
    public function setHash($hash): void
    {
        $this->hash = $hash;
    }

    /**
     * @return mixed
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @param mixed $size
     */
    public function setSize($size): void
    {
        $this->size = $size;
    }

    /**
     * @return mixed
     */
    public function getIsHidden()
    {
        return $this->isHidden;
    }

    /**
     * @param mixed $isHidden
     */
    public function setIsHidden($isHidden): void
    {
        $this->isHidden = $isHidden;
    }

    

    /**
     * @return mixed
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @param mixed $alias
     */
    public function setAlias($alias): void
    {
        $this->alias = $alias;
    }

    /**
     * @return mixed
     */
    public function getAltText()
    {
        return $this->altText;
    }

    /**
     * @param mixed $altText
     */
    public function setAltText($altText): void
    {
        $this->altText = $altText;
    }

    /**
     * @return mixed
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param mixed $description
     */
    public function setDescription($description): void
    {
        $this->description = $description;
    }

    /**
     * @return mixed
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @param mixed $tags
     */
    public function setTags($tags): void
    {
        $this->tags = $tags;
    }



}
