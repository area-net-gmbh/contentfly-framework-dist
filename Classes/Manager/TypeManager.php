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

        // THREE `AnnotationRegistry::registerFile()` CALLS USED TO BE HERE (010-001-0005).
        //
        // They loaded a type's annotation class by hand, because Doctrine's DocParser only
        // resolves an annotation if its class is already known. An attribute names a real
        // class; the autoloader fetches it. That makes the mechanism obsolete, and with it
        // `Type::getAnnotationFile()`.
        $this->types[$type->getAlias()] = $type;
    }

    public function registerPluginType(Type\PluginType $type, Plugin $plugin){

        $type->setPluginKey($plugin->getKey());

        // There used to be a `registerFile()` here as well. A plugin lives under the PSR-4
        // prefix `Plugins\`, so its attribute classes are autoloadable.
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