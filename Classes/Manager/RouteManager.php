<?php
namespace Areanet\PIM\Classes\Manager;

use Areanet\PIM\Classes\Controller\Provider\Base\CustomControllerProvider;
use Areanet\PIM\Classes\Manager;

class RouteManager extends Manager
{
    protected $controllerProviders = array();

    /**
     * @param String $mountPath Mount-Path
     * @param String $controllerName Controller Name
     * @return CustomControllerProvider
     */
    public function mount($mountPath, $controllerName)
    {
        $controllerProvider = new CustomControllerProvider($mountPath, $controllerName);

        $this->controllerProviders[$mountPath] = $controllerProvider;

        return $controllerProvider;
    }

    public function bindRoutes(){
        foreach($this->controllerProviders as $mountPath => $controllerProvider){
            /*
             * connect() is called here directly (009-001-0003).
             *
             * Previously this read `mount($mountPath, $controllerProvider)`. Silex recognises a
             * provider by the fact that it implements `Silex\Api\ControllerProviderInterface`,
             * and then calls `connect()` itself. The providers implement our own interface
             * instead — so `connect()` is called here and the finished collection is passed
             * on. `mount()` accepts it unchanged; the result is the same, just without Silex'
             * detection in between.
             */
            $this->app->mount($mountPath, $controllerProvider->connect($this->app));
        }
    }
}