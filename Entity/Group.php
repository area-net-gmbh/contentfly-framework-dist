<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Helper;
use Areanet\PIM\Classes\I18nPermission;
use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 * @ORM\Entity
 * @ORM\Table(name="pim_group")
 * @PIM\Config(labelProperty="name")
 */
class Group extends Base
{
    use \Custom\Traits\Group;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    protected $name;

    /**
     * @ORM\Column(type="integer")
     */
    protected $tokenTimeout = 30;

    /**
     * Der frueher hier stehende `options="{'default' : 'disabled'}"` ist entfallen: Der Wert
     * war eine **Zeichenkette**, wo Doctrine ein Array erwartet. Bis Doctrine 2.6 wurde das
     * stillschweigend angenommen und ignoriert — der Default hat also nie gewirkt, und die
     * Spalte in `pim_group` traegt keinen. Ab Doctrine 2.20 ist der Konstruktor typisiert und
     * wirft einen TypeError; gefunden mit `006-002-0003`.
     *
     * Entfernt statt korrigiert, weil das verhaltensneutral ist: Ein `options={"default":
     * "disabled"}` wuerde erstmals einen DEFAULT ins Schema schreiben und damit eine
     * Datenbankaenderung ausloesen, die niemand angefordert hat. Der Vorgabewert steht
     * ohnehin im Property.
     *
     * @ORM\Column(type="string")
     * @PIM\Select(options="disabled=nicht erlaubt, enabled=erlaubt")
     */
    protected $apiQueryEnabled = 'disabled';


    /**
     * @ORM\OneToMany(targetEntity="Areanet\PIM\Entity\Permission", mappedBy="group", cascade={"remove"})
     * @PIM\Permissions()
     */
    protected $permissions;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @PIM\I18nPermissions()
     */
    protected $languages;

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
    public function getApiQueryEnabled()
    {
        return $this->apiQueryEnabled;
    }

    /**
     * @param mixed $apiQueryEnabled
     */
    public function setApiQueryEnabled($apiQueryEnabled): void
    {
        $this->apiQueryEnabled = $apiQueryEnabled;
    }

    /**
     * @return mixed
     */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /**
     * @param mixed $permissions
     */
    public function setPermissions($permissions): void
    {
        $this->permissions = $permissions;
    }

    /**
     * @return mixed
     */
    public function getTokenTimeout()
    {
        return $this->tokenTimeout;
    }

    /**
     * @param mixed $tokenTimeout
     */
    public function setTokenTimeout($tokenTimeout): void
    {
        $this->tokenTimeout = $tokenTimeout;
    }

    /**
     * @return mixed
     */
    public function getLanguages()
    {
        if(!$this->languages){
            return null;
        }
        return is_string($this->languages) ? json_decode($this->languages, true) : $this->languages;
    }

    /**
     * @param mixed $languages
     */
    public function setLanguages($languages): void
    {
        if($languages){
            $this->languages = !is_string($languages) ? json_encode($languages) : $languages;
        }

    }

    public function langIsWritable($lang){
        if(!($langPermissions = $this->getLanguages())){
            return true;
        }

        if(empty($langPermissions[$lang])){
            return true;
        }

        return false;
    }

    public function langIsTranslatable($lang){
        if(!($langPermissions = $this->getLanguages())){
            return true;
        }

        if(empty($langPermissions[$lang])){
            return true;
        }

        return $langPermissions[$lang] == I18nPermission::IS_TRANSLATABALE;
    }

    public function langisOnlyReadable($lang){
        return !$this->langIsTranslatable($lang) && !$this->langIsWritable($lang);
    }

}