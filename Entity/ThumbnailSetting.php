<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;


/**
 * @ORM\Entity
 * @ORM\Table(name="pim_thumbnail_setting")
 */
class ThumbnailSetting extends Base
{

    /**
     * @ORM\Column(type="string", length=20, unique=true)
     */
    protected $alias;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $doCut=0;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $width;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $height;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $percent;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $backgroundColor;

    /**
    * @ORM\Column(type="boolean", nullable=true)
    */
    protected $forceJpeg=0;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $isResponsive=0;



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
    public function getDoCut()
    {
        return $this->doCut;
    }

    /**
     * @param mixed $doCut
     */
    public function setDoCut($doCut): void
    {
        $this->doCut = $doCut;
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
    public function getPercent()
    {
        return $this->percent;
    }

    /**
     * @param mixed $percent
     */
    public function setPercent($percent): void
    {
        $this->percent = $percent;
    }

    /**
     * @return mixed
     */
    public function getBackgroundColor()
    {
        return $this->backgroundColor;
    }

    /**
     * @param mixed $backgroundColor
     */
    public function setBackgroundColor($backgroundColor): void
    {
        $this->backgroundColor = $backgroundColor;
    }

    /**
     * @return mixed
     */
    public function getForceJpeg()
    {
        return $this->forceJpeg;
    }

    /**
     * @param mixed $forceJpeg
     */
    public function setForceJpeg($forceJpeg): void
    {
        $this->forceJpeg = $forceJpeg;
    }

    /**
     * @return mixed
     */
    public function getIsResponsive()
    {
        return $this->isResponsive;
    }

    /**
     * @param mixed $isResponsive
     */
    public function setIsResponsive($isResponsive): void
    {
        $this->isResponsive = $isResponsive;
    }

}
