<?php

namespace Areanet\PIM\Classes;


use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManager;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

abstract class Type
{
    /** @var EntityManager $em */
    protected $em;

    /** @var Application $app */
    protected $app;

    protected $entitySettings = array();


    public function __construct(Application $app)
    {
        $this->em   = $app['orm.em'];
        $this->app  = $app;
    }

    public function getPriority()
    {
        return 0;
    }

    public function fromDatabase(Base $object, $entityName, $property, $flatten = false, $level = 0, $propertiesToLoad = array())
    {
        $getter = 'get'.ucfirst($property);

        return $object->$getter();
    }
    
    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {
        $setter = 'set'.ucfirst($property);
        $object->$setter($value);
    }

    /**
     * Is this value empty — as opposed to merely falsy (000-000-0102)?
     *
     * `empty()` says yes to `'0'`, and `'0'` is ordinary content in a text field: an article
     * number, a house number, a floor, a meter reading, a sort value. `StringType::toDatabase()`
     * opened with `if(empty($value))` and therefore stored `''` for it — a silent data loss on
     * every string field, not only the one where `015-000-0001` happened to find it.
     *
     * Empty means exactly three things: no value at all, the empty string, and the empty array
     * (which is how a cleared field arrives from some clients). `0`, `0.0` and `'0'` are content
     * and travel on to the setter, where Doctrine's string type renders them as `'0'`.
     *
     * NOT USED BY THE RELATION TYPES, on purpose. There `empty()` decides whether a link is set,
     * and an id of `'0'` cannot arise: with `DB_GUID_STRATEGY` ids are UUIDs, without it they
     * count up from 1. Treating a falsy id as "no link" also loses no content — it clears a
     * relation the caller did not name.
     */
    protected static function isEmptyValue($value): bool
    {
        return $value === null || $value === '' || $value === array();
    }

    public function setEntitySettings($entitySettings): void{
        $this->entitySettings = $entitySettings;
    }

    public function processSchema($key, $defaultValue, $propertyAnnotations, $entityName)
    {
        $schema = array(
            'type' => $this->getAlias(),
            'dbtype' => $this->getAlias(),
            'sortable' => false,
            'default' => $defaultValue,
            'isFilterable' => false,
            'unique' => false,
            'encoded' => false,
            'secret' => false
        );

        if(isset($propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\Config'])){


            //Areanet\\PIM\\Classes\\Annotations\\Config
            $annotations = $propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\Config'];

            if($annotations->i18n_universal && !empty($this->entitySettings['i18n'])){
                $schema['i18n_universal'] = $annotations->i18n_universal;
            }

            if($annotations->encoded){
                $schema['encoded'] = $annotations->encoded;
            }

            if($annotations->unique){
                $schema['unique'] = $annotations->unique;
            }

            if($annotations->isFilterable){
                $schema['isFilterable'] = $annotations->isFilterable;
            }

            // Out of every query part a client controls — see the annotation (015-000-0012).
            if($annotations->secret){
                $schema['secret'] = $annotations->secret;
            }

        }

        //\Doctrine\ORM\Mapping\Column
        if(isset($propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])){
            $annotations = $propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'];

            if($annotations->length){
                $schema['length'] = $annotations->length;
            }

            if($annotations->unique){
                $schema['unique'] = $annotations->unique;
            }

            if($annotations->type){
                $schema['dbtype'] = $annotations->type;
            }

            $schema['nullable'] = $annotations->nullable ? $annotations->nullable : false;
        }


        return $schema;
    }

    abstract public function doMatch($propertyAnnotations);
    abstract public function getAlias();
    
}