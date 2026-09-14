<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Controller\AuthController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\RouteCollector;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthControllerProvider extends BaseControllerProvider
{


    public function connect(Application $app): RouteCollection
    {
        $app['auth.controller'] = function($app) {
            return new AuthController($app);
        };

        $this->setUpMiddleware($app);


        $controllers = new RouteCollector();

        $checkAuth = function (Request $request, Application $app) {
            if (!$this->anmelden($request, $app)) {
                throw new AccessDeniedHttpException('Access Denied');
            }
        };

        $controllers->post('/login',  "auth.controller:loginAction");

        /*
         * `/refresh` OHNE $checkAuth (013-003-0002).
         *
         * Ein Refresh-Token ist kein JwtAccessToken — der TokenHandler weist es ausdruecklich ab.
         * Haenge man die Anmeldung davor, waere der Endpunkt nur mit einem gueltigen Access-JWT
         * erreichbar, also genau dann nicht, wenn man ihn braucht: nach dessen Ablauf.
         *
         * Er prueft dafuer selbst, und er unterliegt der LoginThrottle.
         */
        $controllers->post('/refresh', "auth.controller:refreshAction");
        $controllers->get('/logout', "auth.controller:logoutAction")->before($checkAuth);

        return $controllers->collection();
    }


}