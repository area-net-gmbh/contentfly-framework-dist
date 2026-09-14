<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Helper;
use Areanet\PIM\Classes\I18nPermission;
use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 *
 * excludeFromSync: permission management — a sync client has nothing to do with it and should not mirror it.
 * Moved here from the hard-wired list in Api.php with 000-000-0013 —
 * an exclusion list that is not written in any annotation cannot be seen by a project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_group')]
#[PIM\Config(labelProperty: 'name', excludeFromSync: true)]
class Group extends Base
{
    use \Custom\Traits\Group;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected $name;

    #[ORM\Column(type: 'integer')]
    protected $tokenTimeout = 30;

    /**
     * The `options="{'default' : 'disabled'}"` that used to be here has been dropped: the value
     * was a **string** where Doctrine expects an array. Up to Doctrine 2.6 this was silently
     * accepted and ignored — so the default never took effect, and the column in `pim_group`
     * carries none. From Doctrine 2.20 on, the constructor is typed and throws a TypeError;
     * found with `006-002-0003`.
     *
     * Removed rather than corrected, because that is behaviour-neutral: an `options={"default":
     * "disabled"}` would write a DEFAULT into the schema for the first time and thereby trigger
     * a database change that nobody asked for. The default value is in the property anyway.
     */
    #[ORM\Column(type: 'string')]
    #[PIM\Select(options: 'disabled=not allowed, enabled=allowed')]
    protected $apiQueryEnabled = 'disabled';


    #[ORM\OneToMany(targetEntity: 'Areanet\\PIM\\Entity\\Permission', mappedBy: 'group', cascade: ['remove'])]
    #[PIM\Permissions]
    protected $permissions;

    #[ORM\Column(type: 'string', nullable: true)]
    #[PIM\I18nPermissions]
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