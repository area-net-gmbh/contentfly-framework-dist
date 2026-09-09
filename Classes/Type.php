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
            'encoded' => false
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