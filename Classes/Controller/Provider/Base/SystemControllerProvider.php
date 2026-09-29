<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Controller\SystemController;
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

        /*
         * THE EMERGENCY LOCK IS GONE (015-000-0019, it replaces 000-000-0015).
         *
         * What stood here: if `authenticate()` threw an `InvalidFieldNameException` — which is
         * what a broken schema does, because loading user and token fails too — the exception was
         * swallowed as long as the body said `method=updateDatabase`. `/system/do` then ran
         * WITHOUT a user and WITHOUT the admin check, and the action behind it is
         * `SchemaTool::updateSchema()`, which under ORM 3 applies the full diff, drops included.
         *
         * The window is real and it is the worst possible one: right after a deploy that changes
         * the mapping of `User` or `Token` and before the operators migrate, anyone could send
         * `POST /system/do {"method":"updateDatabase"}` with any bearer value and have the schema
         * rewritten at a moment of their choosing — dropping the very columns that were about to
         * be migrated.
         *
         * WHAT REPLACES IT: `php bin/console.php appcms:schema:update`. The console needs no open
         * door, it runs as whoever has shell access, and it shows the statements before it
         * applies them. The reasoning of `000-000-0015` — the path that repairs the schema must
         * not be locked out by the broken schema — still holds; it just does not need an HTTP
         * endpoint to hold.
         */
        $checkAuth = function (Request $request, Application $app) {
            if (!$this->authenticate($request, $app)) {
                throw new AccessDeniedHttpException('Access denied', null, 401);
            }
            if (!$app['auth.user']->getIsAdmin()) {
                throw new AccessDeniedHttpException('Access is restricted to administrators', null, 401);
            }
        };

        $controllers->post('/do', "system.controller:doAction")->before($checkAuth);


        return $controllers->collection();
    }


}