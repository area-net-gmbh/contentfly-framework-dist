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

        if($type->getAnnotationFile()){
            if($type instanceof Type\CustomType){
                \Doctrine\Common\Annotations\AnnotationRegistry::registerFile(ROOT_DIR.'/custom/Classes/Annotations/'.$type->getAnnotationFile().'.php');
            }else{
                \Doctrine\Common\Annotations\AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/'.$type->getAnnotationFile().'.php');
            }
        }

        $this->types[$type->getAlias()] = $type;
    }

    public function registerPluginType(Type\PluginType $type, Plugin $plugin){

        $type->setPluginKey($plugin->getKey());
        if($type->getAnnotationFile()) {
            \Doctrine\Common\Annotations\AnnotationRegistry::registerFile(ROOT_DIR . '/plugins/' . $plugin->getKey() . '/Annotations/' . $type->getAnnotationFile() . '.php');
        }

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