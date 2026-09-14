<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * The base for everything the API delivers as an object.
 *
 * `getId()` is not declared here, but it is used — PHPStan rightly reports that
 * (009-003-0002). The method comes from `Base`, `BaseI18n` and `Log`, i.e. from every class
 * that actually extends this one. Instead of inventing it here, it is declared abstract: that
 * puts the condition under which this class works into the code, and a subclass without an id
 * fails at load time instead of at its first delivery.
 */
abstract class Serializable implements \JsonSerializable{

    /** The id of the object. Every inheriting entity provides it. */
    abstract public function getId();


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
         * Nested objects used to be restricted here to the list columns of the user interface
         * (`schema[...]['list']`, fed from `showInList`). The key was removed together with
         * the UI annotations; they now deliver all properties. For clients this is additive;
         * the nesting depth is still limited by `DB_NESTED_LEVELS`.
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