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
             * connect() wird hier selbst gerufen (009-001-0003).
             *
             * Vorher stand hier `mount($mountPath, $controllerProvider)`. Silex erkennt einen
             * Provider daran, dass er `Silex\Api\ControllerProviderInterface` implementiert,
             * und ruft `connect()` dann selbst. Die Provider implementieren stattdessen die
             * eigene Schnittstelle — also wird `connect()` hier gerufen und die fertige
             * Sammlung uebergeben. `mount()` nimmt sie unveraendert entgegen; das Ergebnis ist
             * dasselbe, nur ohne Silex' Erkennung dazwischen.
             */
            $this->app->mount($mountPath, $controllerProvider->connect($this->app));
        }
    }
}