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
            if (!$this->authenticate($request, $app)) {
                throw new AccessDeniedHttpException('Access Denied');
            }
        };

        $controllers->post('/login',  "auth.controller:loginAction");

        /*
         * `/refresh` WITHOUT $checkAuth (013-003-0002).
         *
         * A refresh token is not a JwtAccessToken — the TokenHandler explicitly rejects it. If
         * authentication were put in front of it, the endpoint would only be reachable with a
         * valid access JWT, i.e. precisely not when it is needed: after that token has expired.
         *
         * Instead, it checks for itself, and it is subject to the LoginThrottle.
         */
        $controllers->post('/refresh', "auth.controller:refreshAction");
        $controllers->get('/logout', "auth.controller:logoutAction")->before($checkAuth);

        return $controllers->collection();
    }


}