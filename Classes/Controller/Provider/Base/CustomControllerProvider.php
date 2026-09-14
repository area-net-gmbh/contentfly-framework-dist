<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Route;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\RouteCollector;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CustomControllerProvider extends BaseControllerProvider
{
    protected $controllerName = null;
    protected $routes = array();

    public function __construct($basePath, $controllerName)
    {
        parent::__construct($basePath);

        $this->controllerName = $controllerName;
    }

    public function connect(Application $app): RouteCollection
    {
        $app[$this->basePath.'.controller'] = function() use ($app) {
            $controllerName = $this->controllerName;
            return new $controllerName($app);
        };

        $this->setUpMiddleware($app);

        $controllers = new RouteCollector();
        $checkAuth = function (Request $request, Application $app) {
            if (!$this->anmelden($request, $app) && !$app['auth.user']) {
                throw new AccessDeniedHttpException('Zugriff verweigert', null, 401);
            }
        };

        foreach ($this->routes as $route){
            $method = $route->method;
            $action = $route->action ? $route->action : $route->route.'Action';

            if($route->isSecure){
                $controllers->$method('/'.$route->route, $this->basePath.'.controller:'.$action)->before($checkAuth);
            }else{
                $controllers->$method('/'.$route->route,  $this->basePath.'.controller:'.$action);
            }
        }

        return $controllers->collection();
    }

    public function post($routeName, $isSecure = false, $actionName = null){
        $route = new Route();
        $route->method      = Route::POST;
        $route->route       = $routeName;
        $route->action      = $actionName;
        $route->isSecure    = $isSecure;

        $this->routes[$routeName] = $route;

        return $this;
    }

    public function get($routeName, $isSecure = false, $actionName = null){
        $route = new Route();
        $route->method      = Route::GET;
        $route->route       = $routeName;
        $route->action      = $actionName;
        $route->isSecure    = $isSecure;

        $this->routes[$routeName] = $route;

        return $this;
    }

    public function match($routeName, $isSecure = false, $actionName = null){
        $route = new Route();
        $route->method      = Route::MATCH;
        $route->route       = $routeName;
        $route->action      = $actionName;
        $route->isSecure    = $isSecure;

        $this->routes[$routeName] = $route;

        return $this;
    }


}