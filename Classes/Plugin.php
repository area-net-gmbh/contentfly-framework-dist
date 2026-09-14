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
     * Wird beim Initialisieren des Plugins aufgerufen, kann im Plugin überschrieben/angepasst werden
     */
    public function init(){

    }

    /**
     * Initialisieren der Composer-Funktion im Plugin
     */
    private function initComposer(): void{
        if(file_exists(Paths::plugins().'/'.$this->key.'/vendor/autoload.php')){
            require_once Paths::plugins().'/'.$this->key.'/vendor/autoload.php';
        }
    }

    /**
     * Initialisieren von eigenen Doctrine-Entitäten im Plugin.
     *
     * ATTRIBUTE STATT ANNOTATIONEN (010-001-0004). Hier stand
     * `$ormConfig->newDefaultAnnotationDriver(...)`.
     *
     * DIE UMSTELLUNG WAR NICHT WAHLFREI. Eine Plugin-Entity erbt von
     * `Areanet\PIM\Entity\Base`, und bei einer MappedSuperclass setzt Doctrine an den
     * geerbten Feldern kein `inherited` — der Treiber der Unterklasse liest sie neu. Ein
     * Annotation-Treiber faende an der umgestellten `Base` nichts mehr und meldete
     * „No identifier/primary key specified". Gemessen und begruendet in `010-001-0003`.
     *
     * WAS GEPRUEFT IST UND WAS NICHT. `tests/Unit/Manager/PluginManagerTest.php` deckt diese
     * Methode ab — ein Spion auf der Doctrine-Konfiguration haelt fest, dass ein
     * `AttributeDriver` fuer `plugins/<Key>/Entity` unter `Plugins\<Key>\Entity` eingehaengt
     * wird. Der Test ist mit der Umstellung umgedreht worden und war vorher zu Recht rot.
     *
     * Was er NICHT zeigt: dass Doctrine aus einer echten Plugin-Entity danach auch Metadaten
     * liest. `plugins/` ist leer, seit `006-003-0002` — dort liess sich schon
     * `AnnotationRegistry::registerFile()` an nichts vorfuehren. Wer das erste Plugin baut,
     * prueft das als Erstes.
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