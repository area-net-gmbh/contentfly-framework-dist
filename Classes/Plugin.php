<?php
namespace Areanet\PIM\Classes;


use Areanet\PIM\Classes\Type\PluginType;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

abstract class Plugin
{
    /** @var Application */
    protected $app;

    /**
     * @var string Eindeutiger Plugin-Key
     */
    protected $key          = null;
    /**
     * @var string Namespace des Plugins, wird automatisch ermittelt
     */
    protected $namespace    = null;
    /**
     * @var mixed|null Plugin-Options, können beim Registrieren über den Plugin-Manager übergeben werden
     */
    protected $options      = null;
    /**
     * @var bool Plugin nutzt eigene Doctrine-Entitäten
     */
    private $doUseORM       = false;

    /**
     * Manager constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app, $options = null)
    {
        $classNameParts     = explode('\\', get_class($this));
        $this->key          = $classNameParts[1];
        $this->namespace    = $classNameParts[0].'\\'.$classNameParts[1];
        $this->options      = $options;
        $this->app          = $app;

        $this->initComposer();
        $this->init();
    }

    /**
     * @return string[]
     */
    final public function getEntities(){
        if(!$this->doUseORM){
           return array();
        }

        $entities = array();

        $entityFolder = ROOT_DIR.'/plugins/'.$this->key.'/Entity';

        foreach (new \DirectoryIterator($entityFolder) as $fileInfo) {
            if($fileInfo->isDot() || $fileInfo->getExtension() != 'php') continue;
            if(substr($fileInfo->getBasename('.php'), 0, 1) == '.') continue;

            $entities[] = $this->getNamespace().'\\Entity\\'.$fileInfo->getBasename('.php');
        }

        return $entities;
    }

    /**
     * @return string
     */
    final public function getNamespace(){
        return $this->namespace;
    }

    /**
     * @return null
     */
    final public function getKey(){
        return $this->key;
    }

    /**
     * Wird beim Initialisieren des Plugins aufgerufen, kann im Plugin überschrieben/angepasst werden
     */
    public function init(){

    }

    /**
     * Initialisieren der Composer-Funktion im Plugin
     */
    private function initComposer(): void{
        if(file_exists(ROOT_DIR.'/plugins/'.$this->key.'/vendor/autoload.php')){
            require_once ROOT_DIR.'/plugins/'.$this->key.'/vendor/autoload.php';
        }
    }

    /**
     * Initialisieren von eigenen Doctrine-Entitäten im Plugin
     */
    private function initORM(): void{
        $ormConfig  = $this->app['orm.em']->getConfiguration();
        if(!is_dir(ROOT_DIR.'/plugins/'.$this->key.'/Entity')){
            mkdir(ROOT_DIR.'/plugins/'.$this->key.'/Entity');
        }
        $driver     = $ormConfig->newDefaultAnnotationDriver(array(ROOT_DIR.'/plugins/'.$this->getKey().'/Entity'), false);
        $ormConfig->getMetadataDriverImpl()->addDriver($driver, $this->getNamespace().'\\Entity');
    }

    /**
     * @param PluginType $plugin Instanz des benutzerdefinierten Types
     */
    final protected function registerPluginType(PluginType $plugin){
        $this->app['typeManager']->registerPluginType($plugin, $this);
    }

    /**
     * Nutzung von eigenen Entitäten im Ordner 'Entity' des Plugins
     */
    final protected function useORM(){
        $this->doUseORM = true;
        $this->initORM();
    }
}