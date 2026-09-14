<?php
namespace Areanet\PIM\Classes\Types;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Entity\Base;


class TimeType extends Type
{
    /**
     * Output format of the time values. Previously lived in the UI annotation `@PIM\Time`;
     * that annotation is gone, the format stays — `fromDatabase()` delivers the API response
     * with it.
     */
    const DEFAULT_FORMAT = 'H:i';

    public function getAlias()
    {
        return 'time';
    }


    public function processSchema($key, $defaultValue, $propertyAnnotations, $entityName){
        $schema             = parent::processSchema($key, $defaultValue, $propertyAnnotations, $entityName);
        $schema['format']   = self::DEFAULT_FORMAT;
        $schema['dbType']   = "time";

        return $schema;
    }

    public function doMatch($propertyAnnotations){

        if(!isset($propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
            return false;
        }

        $annotation = $propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'];

        return ($annotation->type == 'time');
    }

    public function fromDatabase(Base $object, $entityName, $property, $flatten = false, $level = 0, $propertiesToLoad = array())
    {
        $getter = 'get'.ucfirst($property);
        
        if(!$object->$getter() instanceof \DateTime){
            return null;
        }

        $config = $this->app['schema'][ucfirst($entityName)]['properties'][$property];
        
        return $object->$getter()->format($config['format']);
    }


    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {

        $setter = 'set'.ucfirst($property);
        $getter = 'get'.ucfirst($property);

        if($value){
            if($value instanceof \DateTime){
                $object->$setter($value);
            }else{
                $time = explode(':', $value);

                $datetime = new \DateTime();
                $datetime->setTime($time[0], $time[1]);

                $object->$setter($datetime);
            }

        }else{
            $object->$setter(null);
        }


    }
}
