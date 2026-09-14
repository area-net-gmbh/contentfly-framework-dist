<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Controller\SystemController;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\RouteCollector;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SystemControllerProvider extends BaseControllerProvider
{


    public function connect(Application $app): RouteCollection
    {
        $app['system.controller'] = function($app) {
            return new SystemController($app);
        };

        $this->setUpMiddleware($app);


        $controllers = new RouteCollector();

        $checkAuth = function (Request $request, Application $app) {
            try {
                if (!$this->authenticate($request, $app)) {
                    throw new AccessDeniedHttpException('Access denied', null, 401);
                }
                if (!$app['auth.user']->getIsAdmin()) {
                    throw new AccessDeniedHttpException('Access is restricted to administrators', null, 401);
                }
            }catch(InvalidFieldNameException $e){

                /*
                 * THE EMERGENCY LOCK — and why only updateDatabase is left in it
                 * (000-000-0015).
                 *
                 * If the schema is broken, even loading the user and token fails with an
                 * InvalidFieldNameException. Then the very path that puts the schema back in
                 * order would be blocked too. This exception lets exactly that path through.
                 *
                 * `validateORM` used to be here as well — the method does not exist in the
                 * controller. The condition opened the door for something that was rejected
                 * behind it anyway: an exception leading nowhere.
                 *
                 * DROPPED INSTEAD OF RESTORED. Doctrine would provide everything via
                 * SchemaValidator, and the import is still at the top of the file — but a
                 * restored method would be a second endpoint reachable WITHOUT a token and
                 * WITHOUT admin rights. An emergency lock should be as small as possible;
                 * whoever wants to check the schema state can do so with a console command
                 * that needs no open door.
                 */
                if(($request->request->all()['method'] ?? null) == 'updateDatabase'){

                }else{
                    throw $e;
                }
            }
        };

        $controllers->post('/do', "system.controller:doAction")->before($checkAuth);


        return $controllers->collection();
    }


}