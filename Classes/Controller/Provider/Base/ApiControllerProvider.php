<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\RouteCollector;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ApiControllerProvider extends BaseControllerProvider
{


    public function connect(Application $app): RouteCollection
    {
        $app['api.controller'] = function($app) {
            return new ApiController($app);
        };

        $this->setUpMiddleware($app);

        $controllers = new RouteCollector();

        $checkAuth = function (Request $request, Application $app) {
            if (!$this->authenticate($request, $app)) {
                throw new ContentflyException(Messages::contentfly_general_access_denied, null, Messages::contentfly_status_invalid_token);
            }
        };

        /*
         * `/api/login` AND `/api/logout` HAVE BEEN REMOVED (013-001-0005).
         *
         * They pointed to `api.controller:loginAction` and `:logoutAction` — neither method
         * exists in the ApiController, and neither ever existed in this tree.
         *
         * They were never noticed because they never even reached the router: `RouteCollector`
         * numbers routes per provider, `/api/login` was named `login_0` and was displaced when
         * `/auth/login` with the same name was mounted. The name clash is fixed by the same
         * task — which would have made these two routes effective for the first time, namely
         * as an error from the controller resolver instead of a 405.
         *
         * REMOVED INSTEAD OF REDIRECTED. Pointing them to `auth.controller` would be a second
         * name for the same thing and a second surface that has to be secured. The working
         * routes are `/auth/login` and `/auth/logout`; a call to `/api/login` responds with
         * 405, like any unknown path.
         */
        $controllers->post('/single', "api.controller:singleAction")->before($checkAuth);
        $controllers->post('/list',   "api.controller:listAction")->before($checkAuth);
        $controllers->post('/tree',   "api.controller:treeAction")->before($checkAuth);
        $controllers->post('/tree2',   "api.controller:tree2Action")->before($checkAuth);
        $controllers->post('/translations',   "api.controller:translationsAction")->before($checkAuth);
        $controllers->post('/all',   "api.controller:allAction")->before($checkAuth);
        $controllers->post('/delete', "api.controller:deleteAction")->before($checkAuth);
        $controllers->post('/update', "api.controller:updateAction")->before($checkAuth);
        $controllers->post('/replace', "api.controller:replaceAction")->before($checkAuth);
        $controllers->post('/multiupdate', "api.controller:multiupdateAction")->before($checkAuth);
        $controllers->post('/insert', "api.controller:insertAction")->before($checkAuth);
        $controllers->post('/query', "api.controller:queryAction")->before($checkAuth);
        $controllers->post('/count', "api.controller:countAction")->before($checkAuth);
        $controllers->post('/deleted', "api.controller:deletedAction")->before($checkAuth);
        $controllers->get('/schema', "api.controller:schemaAction")->before($checkAuth);
        $controllers->get('/config', "api.controller:configAction");

        return $controllers->collection();
    }


}