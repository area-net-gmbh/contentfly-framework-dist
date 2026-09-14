<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\FileController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\RouteCollector;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class FileControllerProvider extends BaseControllerProvider
{


    public function connect(Application $app): RouteCollection
    {
        $app['file.controller'] = function() use ($app) {
            return new FileController($app);
        };

        $this->setUpMiddleware($app);


        $controllers = new RouteCollector();

        $checkAuth = function (Request $request, Application $app) {
            if (!$this->anmelden($request, $app)) {
                throw new AccessDeniedHttpException('Access Denied');
            }
        };

        $controllers->post('/upload', "file.controller:uploadAction")->before($checkAuth);
        $controllers->post('/overwrite', "file.controller:overwriteAction")->before($checkAuth);
        $controllers->get('/get/{id}', "file.controller:getAction");
        $controllers->get('/get/{id}/s-{size}', "file.controller:getAction");
        $controllers->get('/get/{id}/{alias}', "file.controller:getAction");
        $controllers->get('/get/{id}/s-{size}/{alias}', "file.controller:getAction");
        $controllers->get('/get/{id}/{size}/{alias}', "file.controller:getAction");
        $controllers->get('/get/{id}/{size}/{variant}/{alias}', "file.controller:getAction");
        $controllers->get('/get/{id}/s-{size}/{variant}/{alias}', "file.controller:getAction");

        return $controllers->collection();
    }


}