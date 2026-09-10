<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\Routing\Routensammlung;
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

        $controllers = new Routensammlung();

        $checkAuth = function (Request $request, Application $app) {
            if (!$this->checkToken($request, $app)) {
                throw new ContentflyException(Messages::contentfly_general_access_denied, null, Messages::contentfly_status_invalid_token);
            }
        };

        /*
         * `/api/login` UND `/api/logout` SIND ENTFALLEN (013-001-0005).
         *
         * Sie zeigten auf `api.controller:loginAction` und `:logoutAction` — beide Methoden gibt
         * es im ApiController nicht und gab es in diesem Baum nie.
         *
         * Aufgefallen sind sie nie, weil sie den Router gar nicht erreichten: `Routensammlung`
         * zaehlt je Provider durch, `/api/login` hiess `login_0` und wurde beim Mounten von
         * `/auth/login` gleichen Namens verdraengt. Der Namensvetter ist mit demselben Task
         * behoben — womit diese beiden Routen erstmals wirksam geworden waeren, und zwar als
         * Fehler aus dem Controller-Resolver statt als 405.
         *
         * ENTFERNT STATT UMGEBOGEN. Sie auf `auth.controller` zeigen zu lassen waere ein
         * zweiter Name fuer dieselbe Sache und eine zweite Oberflaeche, die man absichern muss.
         * Die funktionierenden Routen sind `/auth/login` und `/auth/logout`; ein Aufruf von
         * `/api/login` antwortet mit 405, wie jeder unbekannte Pfad.
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

        return $controllers->sammlung();
    }


}