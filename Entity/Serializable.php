<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

abstract class Serializable implements \JsonSerializable{

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toValueObject();
    }

    public function toValueObject(?Application $app = null, $entityName = null, $flatten = false, $propertiesToLoad = array(), $level = 0, $forceLoadAll = false)
    {

        $result = new \stdClass();

        if($level > Adapter::getConfig()->DB_NESTED_LEVELS){
            $result->id = $this->getId();
            return $result;
        }

        $schema = null;
        $user   = null;

        if($app){
            $schema = $app['schema'];
        }

        /*
         * Verschachtelte Objekte waren hier auf die Listenspalten der Oberfläche
         * beschränkt (`schema[...]['list']`, gespeist aus `showInList`). Der Schlüssel ist
         * mit den UI-Annotationen entfallen; sie liefern jetzt alle Eigenschaften. Für
         * Clients ist das additiv, die Verschachtelungstiefe begrenzt weiterhin
         * `DB_NESTED_LEVELS`.
         */

        foreach ($this as $property => $value) {

            if(count($propertiesToLoad) && !in_array($property, $propertiesToLoad)){

                continue;
            }

            if(!$app || !isset($schema[$entityName]['properties'][$property])){
                continue;
            }
            $config = $schema[$entityName]['properties'][$property];

            $typeObject = $app['typeManager']->getType($config['type']);
            if(!$typeObject){
                throw new \Exception("toValueObject(): Unkown Type $typeObject for $property for entity $entityName", 500);
            }

            $result->$property = $typeObject->fromDatabase($this, $entityName, $property, $flatten, $level, $propertiesToLoad);

        }
        
        return $result;
    }
}