<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]

class Base extends Serializable
{

    /**
     * Die Id.
     *
     * `CUSTOM` statt `UUID` seit 009-005-0002: Doctrines `ORM\Id\UuidGenerator` liess die
     * Datenbank die GUID erzeugen (`SELECT UUID()`) und stuetzte sich dafuer auf
     * `AbstractPlatform::getGuidExpression()` — die Methode gibt es in DBAL 3 nicht mehr.
     * Erzeugt wird sie jetzt in PHP, siehe Areanet\PIM\Classes\ORM\Id\UuidGenerator.
     *
     * `@ORM\CustomIdGenerator` steht auch dann hier, wenn die Integer-Strategie laeuft
     * (APPCMS_ID_STRATEGY = 'AUTO'). Doctrine liest die Angabe nur bei `CUSTOM` aus; sie
     * bedingt zu setzen ginge in einer Annotation nicht, ohne die Konstante zu verdoppeln.
     */
    #[ORM\Column(type: APPCMS_ID_TYPE)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: APPCMS_ID_STRATEGY)]
    #[ORM\CustomIdGenerator(class: 'Areanet\\PIM\\Classes\\ORM\\Id\\UuidGenerator')]
    protected $id;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime', nullable: true, options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected $created;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime', nullable: true, options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected $modified;


    #[ORM\Column(type: 'integer', options: ['default' => 0], nullable: true)]
    protected $views;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\User')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    protected $user;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\User')]
    #[ORM\JoinColumn(name: 'usercreated_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    protected $userCreated;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    protected $isIntern = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[PIM\Virtualjoin(targetEntity: 'Areanet\\PIM\\Entity\\User')]
    protected $users;

    #[ORM\Column(type: 'text', nullable: true)]
    #[PIM\Virtualjoin(targetEntity: 'Areanet\\PIM\\Entity\\Group')]
    protected $groups;



    /**
     */
    protected $disableModifiedTime = false;


    public function __construct()
    {
        $this->created   = new \DateTime();
        $this->modified  = new \DateTime();
    }

    public function __call($name, $arguments)
    {
        if(strlen($name) <= 3){
            throw new ContentflyException(Messages::contentfly_general_invalid_gettersetter, $name);
        }

        $method   = substr($name, 0, 3);
        $property = lcfirst(substr($name, 3));

        if(!property_exists($this, $property)){
            throw new ContentflyException(Messages::contentfly_general_property_not_exists, get_class($this).'::'.get_class($this));
        }

        switch($method){
            case 'set':
                $this->$property = $arguments[0];
                break;
            case 'get':
                return $this->$property;
                break;
            default:
                throw new ContentflyException(Messages::contentfly_general_invalid_gettersetter, $name);
        }


    }


    public function doDisableModifiedTime($disable): void{
        $this->disableModifiedTime = $disable;
    }

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
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @param \DateTime $created
     */
    public function setCreated($created): void
    {
        if($created instanceof \Datetime) {
            $this->created = $created;
        } else if($created !== null) {
            $this->created = new \Datetime($created);
        }
    }

    /**
     * @return \DateTime
     */
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * @param \DateTime $modified
     */
    public function setModified($modified): void
    {
        if($modified instanceof \Datetime) {
            $this->modified = $modified;
        } else if($modified !== null) {
            $this->modified = new \Datetime($modified);
        }
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getUserCreated()
    {
        return $this->userCreated;
    }

    /**
     * @param mixed $userCreated
     */
    public function setUserCreated($userCreated): void
    {
        $this->userCreated = $userCreated;
    }


    /**
     * @return mixed
     */
    public function getViews()
    {
        return $this->views;
    }

    /**
     * @param mixed $views
     */
    public function setViews($views): void
    {
        $this->views = $views;
    }

    /**
     * @return mixed
     */
    public function getDisableModifiedTime()
    {
        return $this->disableModifiedTime;
    }

    /**
     * @param mixed $disableModifiedTime
     */
    public function setDisableModifiedTime($disableModifiedTime): void
    {
        $this->disableModifiedTime = $disableModifiedTime;
    }

    /**
     * @return mixed
     */
    public function getIsIntern()
    {
        return $this->isIntern;
    }

    /**
     * @param mixed $isIntern
     */
    public function setIsIntern($isIntern): void
    {
        $this->isIntern = $isIntern;
    }

    /**
     * @return mixed
     */
    public function getUsers($asString = false)
    {
        if($asString){
            return $this->users;
        }

        $data = array();

        if($this->users) {
            $ids = explode(',', $this->users);

            foreach ($ids as $id) {
                if (empty($id)) continue;

                $data[] = array(
                    'id' => $id
                );
            }
        }

        return $data;
    }

    /**
     * @return boolean
     */
    public function hasUserId($id)
    {
        /*
         * `users` ist eine nullable Spalte: Kein Eintrag heisst keine Treffer. Bis PHP 8.0
         * ergab explode(',', null) still array(""), seit 8.1 ist es eine Deprecation
         * (000-000-0023). Das Ergebnis bleibt dasselbe — der leere Fall steht jetzt da.
         */
        $ids = $this->users !== null ? explode(',', $this->users) : array();

        return in_array($id, $ids) || $this->id == $id;
    }

    /**
     * @param mixed $users
     */
    public function setUsers($users): void
    {
        $this->users = $users;
    }

    /**
     * @return mixed
     */
    public function getGroups($asString = false)
    {
        if($asString){
            return $this->groups;
        }

        $data = array();

        if($this->groups) {
            $ids = explode(',', $this->groups);

            foreach ($ids as $id) {
                if (empty($id)) continue;

                $data[] = array(
                    'id' => $id
                );
            }
        }

        return $data;
    }

    /**
     * @return boolean
     */
    public function hasGroupId($id)
    {
        /*
         * `groups` ist eine nullable Spalte: Kein Eintrag heisst keine Treffer. Bis PHP 8.0
         * ergab explode(',', null) still array(""), seit 8.1 ist es eine Deprecation
         * (000-000-0023). Das Ergebnis bleibt dasselbe — der leere Fall steht jetzt da.
         */
        $ids = $this->groups !== null ? explode(',', $this->groups) : array();

        return in_array($id, $ids);
    }

    /**
     * @param mixed $groups
     */
    public function setGroups($groups): void
    {
        $this->groups = $groups;
    }



    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateModifiedDatetime(): void {
        // update the modified time

        if(!$this->disableModifiedTime) {

            $this->setModified(new \DateTime());
        }
    }

}
