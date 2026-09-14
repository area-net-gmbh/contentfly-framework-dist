<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Classes\Type\PluginType;
use Areanet\PIM\Classes\Kernel\Paths;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;

abstract class Plugin
{
    /** @var Application */
    protected $app;

    /**
     * @var string Unique plugin key
     */
    protected $key          = null;
    /**
     * @var string Namespace of the plugin, determined automatically
     */
    protected $namespace    = null;
    /**
     * @var mixed|null Plugin options, can be passed via the plugin manager on registration
     */
    protected $options      = null;
    /**
     * @var bool Plugin uses its own Doctrine entities
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

        $entityFolder = Paths::plugins().'/'.$this->key.'/Entity';

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
     * Called when the plugin is initialised, can be overridden/customised in the plugin
     */
    public function init(){

    }

    /**
     * Initialises the Composer functionality in the plugin
     */
    private function initComposer(): void{
        if(file_exists(Paths::plugins().'/'.$this->key.'/vendor/autoload.php')){
            require_once Paths::plugins().'/'.$this->key.'/vendor/autoload.php';
        }
    }

    /**
     * Initialises the plugin's own Doctrine entities.
     *
     * ATTRIBUTES INSTEAD OF ANNOTATIONS (010-001-0004). This used to be
     * `$ormConfig->newDefaultAnnotationDriver(...)`.
     *
     * THE SWITCH WAS NOT OPTIONAL. A plugin entity inherits from `Areanet\PIM\Entity\Base`,
     * and for a MappedSuperclass Doctrine sets no `inherited` on the inherited fields — the
     * subclass's driver reads them anew. An annotation driver would find nothing on the
     * converted `Base` any more and would report "No identifier/primary key specified".
     * Measured and justified in `010-001-0003`.
     *
     * WHAT IS VERIFIED AND WHAT IS NOT. `tests/Unit/Manager/PluginManagerTest.php` covers this
     * method — a spy on the Doctrine configuration records that an `AttributeDriver` for
     * `plugins/<Key>/Entity` is hooked in under `Plugins\<Key>\Entity`. The test was inverted
     * with the switch and was rightly red before.
     *
     * What it does NOT show: that Doctrine then actually reads metadata from a real plugin
     * entity. `plugins/` has been empty since `006-003-0002` — even back then
     * `AnnotationRegistry::registerFile()` could not be demonstrated on anything there. Whoever
     * builds the first plugin checks this first.
     */
    private function initORM(): void{
        $ormConfig  = $this->app['orm.em']->getConfiguration();
        if(!is_dir(Paths::plugins().'/'.$this->key.'/Entity')){
            mkdir(Paths::plugins().'/'.$this->key.'/Entity');
        }
        $driver     = new AttributeDriver(array(Paths::plugins().'/'.$this->getKey().'/Entity'));
        $ormConfig->getMetadataDriverImpl()->addDriver($driver, $this->getNamespace().'\\Entity');
    }

    /**
     * @param PluginType $plugin Instance of the custom type
     */
    final protected function registerPluginType(PluginType $plugin){
        $this->app['typeManager']->registerPluginType($plugin, $this);
    }

    /**
     * Use of the plugin's own entities in the plugin's 'Entity' folder
     */
    final protected function useORM(){
        $this->doUseORM = true;
        $this->initORM();
    }
}