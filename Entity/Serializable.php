<?php
namespace Areanet\PIM\Entity;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * Die Basis fuer alles, was die API als Objekt ausliefert.
 *
 * `getId()` ist hier nicht deklariert, wird aber benutzt — PHPStan meldet das zu Recht
 * (009-003-0002). Die Methode kommt aus `Base`, `BaseI18n` und `Log`, also aus jeder Klasse,
 * die tatsaechlich von hier erbt. Statt sie hier zu erfinden, ist sie als abstrakt
 * deklariert: Damit steht die Bedingung im Code, unter der diese Klasse funktioniert, und
 * eine Ableitung ohne Id faellt beim Laden auf statt bei der ersten Auslieferung.
 */
abstract class Serializable implements \JsonSerializable{

    /** Die Id des Objekts. Jede erbende Entity bringt sie mit. */
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