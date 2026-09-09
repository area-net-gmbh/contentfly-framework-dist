<?php
namespace Areanet\PIM\Classes\Manager;

use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Manager;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Plugin;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

class TypeManager extends Manager
{
    protected $types = array();

    public function registerType(Type $type){

        if($type instanceof Type\PluginType){
            throw new ContentflyException(Messages::contentfly_general_use_plugin_register_method, get_class($type));
        }

        // HIER STANDEN DREI `AnnotationRegistry::registerFile()` (010-001-0005).
        //
        // Sie luden die Annotationsklasse eines Typs von Hand nach, weil Doctrines DocParser
        // eine Annotation nur aufloest, wenn ihre Klasse bereits bekannt ist. Ein Attribut
        // nennt eine echte Klasse; der Autoloader holt sie. Damit ist die Mechanik
        // gegenstandslos, und mit ihr `Type::getAnnotationFile()`.
        $this->types[$type->getAlias()] = $type;
    }

    public function registerPluginType(Type\PluginType $type, Plugin $plugin){

        $type->setPluginKey($plugin->getKey());

        // Auch hier stand ein `registerFile()`. Ein Plugin liegt unter dem PSR-4-Praefix
        // `Plugins\`, seine Attributklassen sind also autoladbar.
        $this->types[$type->getAlias()] = $type;
    }


    public function getTypes(){
        return $this->types;
    }

    public function getType($alias){
        if(!isset($this->types[$alias])){
            return null;
        }

        return $this->types[$alias];
    }
}