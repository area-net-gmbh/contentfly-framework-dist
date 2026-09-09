<?php
namespace Areanet\PIM\Classes\Controller\Provider\Base;

use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\Provider\BaseControllerProvider;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Controller\SystemController;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SystemControllerProvider extends BaseControllerProvider
{


    public function connect(Application $app)
    {
        $app['system.controller'] = function($app) {
            return new SystemController($app);
        };

        $this->setUpMiddleware($app);


        $controllers = $app['controllers_factory'];

        $checkAuth = function (Request $request, Application $app) {
            try {
                if (!$this->checkToken($request, $app)) {
                    throw new AccessDeniedHttpException('Zugriff verweigert', null, 401);
                }
                if (!$app['auth.user']->getIsAdmin()) {
                    throw new AccessDeniedHttpException('Zugriff nur für Administratoren gestattet', null, 401);
                }
            }catch(InvalidFieldNameException $e){

                /*
                 * DAS NOTSCHLOSS — und warum nur noch updateDatabase darin steht
                 * (000-000-0015).
                 *
                 * Ist das Schema kaputt, scheitert schon das Laden von Benutzer und Token mit
                 * einer InvalidFieldNameException. Dann waere auch der Weg versperrt, der das
                 * Schema wieder in Ordnung bringt. Diese Ausnahme laesst genau ihn durch.
                 *
                 * `validateORM` stand hier ebenfalls — die Methode gibt es im Controller
                 * nicht. Die Bedingung oeffnete die Tuer fuer etwas, das dahinter ohnehin
                 * abgewiesen wurde: eine Ausnahme ins Leere.
                 *
                 * GESTRICHEN STATT WIEDERHERGESTELLT. Doctrine braechte mit SchemaValidator
                 * alles mit, und der Import steht noch oben in der Datei — aber eine
                 * wiederhergestellte Methode waere ein zweiter Endpunkt, der OHNE Token und
                 * OHNE Adminrecht erreichbar ist. Ein Notschloss soll so klein sein wie
                 * moeglich; wer den Schemazustand pruefen will, kann das mit einem
                 * Console-Command tun, der keine offene Tuer braucht.
                 */
                if($request->get('method') == 'updateDatabase'){

                }else{
                    throw $e;
                }
            }
        };

        $controllers->post('/do', "system.controller:doAction")->before($checkAuth);


        return $controllers;
    }


}