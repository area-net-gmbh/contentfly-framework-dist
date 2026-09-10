<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Controller\AuthController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\Routensammlung;
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


        $controllers = new Routensammlung();

        $checkAuth = function (Request $request, Application $app) {
            if (!$this->anmelden($request, $app)) {
                throw new AccessDeniedHttpException('Access Denied');
            }
        };

        $controllers->post('/login',  "auth.controller:loginAction");
        $controllers->get('/logout', "auth.controller:logoutAction")->before($checkAuth);

        return $controllers->sammlung();
    }


}