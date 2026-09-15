<?php
namespace Areanet\PIM\Classes\Config;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Exceptions\Config\NotFoundException;


/**
 * Class Factory
 *
 * Factory class to manage config setting for different server hosts (local, development, production,...)
 *
 * @package Areanet\PIM\Classes\Config
 */

class Factory{

    /**
     * @var Factory
     */
    protected static $_instance = null;

    /**
     * @var Config[]
     */
    protected $configSettings = array();


    /**
     * @param string $host Hostname
     * @return Config
     */
    public function getConfig($host = 'default')
    {

        if(!isset($this->configSettings[$host])){

            return $this->configSettings['default'];
        }

        return $this->configSettings[$host];
    }

    /**
     * Whether a configuration has been set at all (000-000-0024).
     *
     * `getConfig()` cannot be asked that: without a default it reads an undefined key and returns
     * null. `Kernel\Start` needs the answer when the start fails before `custom/config.php` ran.
     */
    public function hasConfig(): bool
    {
        return isset($this->configSettings['default']);
    }

    /**
     * @param Config $config Config-Settings
     */
    public function setConfig(Config $config): void
    {
        $host = $config->getHost() ? $config->getHost() : 'default';
        $this->configSettings[$host] = $config;

    }


    /**
     * Get singleton instance
     * @return Factory;
     */
    public static function getInstance()
    {
        if(self::$_instance === null){
            self::$_instance = new Factory();
        }

        return self::$_instance;
    }


    /**
     * Disable cloning
     */
    protected function __clone(){}

    /**
     * Disable creating manual instances
     */
    protected function __construct(){}


}