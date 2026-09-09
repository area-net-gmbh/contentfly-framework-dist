<?php
namespace Areanet\PIM\Classes\Controller\Provider;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseControllerProvider implements ControllerProviderInterface
{

    const LOGIN_PATH           = '/login';
    const TOKEN_HEADER_KEY_ALT = 'X-XSRF-TOKEN';
    const TOKEN_HEADER_KEY     = 'appcms-token';
    const TOKEN_REQUEST_KEY    = '_token';
    protected $basePath = '';

    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }


    protected function setUpMiddleware(Application $app)
    {
        $app->before(function (Request $request)use ($app) {

            // Nicht installiert: Frueher fuehrte hier ein Redirect auf die Installer-Maske.
            // Die gibt es nicht mehr - installiert wird auf der Kommandozeile. Statt eines
            // Redirects ins Leere sagen wir, was zu tun ist.
            if (Adapter::getConfig()->DB_HOST == '$SET_DB_HOST') {
                return new \Symfony\Component\HttpFoundation\JsonResponse(array(
                    'message' => 'Contentfly ist nicht installiert. Installation ausfuehren: php bin/console.php appcms:install'
                ), 503);
            }

            if ($request->headers->get('Content-Type') && (0 === strpos($request->headers->get('Content-Type'), 'application/json'))) {
                $data = null;
                if($request->getContent()) {
                    $data = json_decode($request->getContent(), true);
                    if ($data === null) {
                        throw new \Exception("Inavlid JSON-Data", 500);
                    }
                }
                $request->request->replace(is_array($data) ? $data : array());
            }else{

            }

            if(!is_object($request->get('_controller'))) {
                $event = new \Areanet\PIM\Classes\Event();
                $event->setParam('request', $request);
                $event->setParam('app', $app);
                /*
                 * `_controller` fehlt bei internen Anfragen ganz — der Fall ist gueltig, der
                 * Hook hat dann nichts zu verteilen. Bis PHP 8.0 ergab strtolower(null) still
                 * "", seit 8.1 ist es eine Deprecation und ab PHP 9 ein TypeError
                 * (000-000-0023). Benannt statt weggecastet: Ein (string)-Cast haette die
                 * Meldung beseitigt und die Frage versteckt, warum hier null ankommt.
                 */
                $controller = $request->get('_controller');
                if (!is_string($controller)) {
                    return;
                }

                $controllerAction = str_replace(':', '.', strtolower($controller));
                if (empty($controllerAction)) {
                    return;
                }

                $controllerParts = explode('.', $controllerAction);
                $app['dispatcher']->dispatch($event, 'pim.controller.before.' . $controllerParts[0] . '.' . $controllerParts[2]);

                $controllerParts = explode('.', $controllerAction);
                $app['dispatcher']->dispatch($event, 'pim.controller.before.' . $controllerParts[0]);
                $app['dispatcher']->dispatch($event, 'pim.controller.before');
            }
        });

        $app->after(function (Request $request, Response $response) use ($app) {

            if(!is_object($request->get('_controller'))) {
                $event = new \Areanet\PIM\Classes\Event();
                $event->setParam('request', $request);
                $event->setParam('response', $response);
                $event->setParam('app', $app);

                /*
                 * `_controller` fehlt bei internen Anfragen ganz — der Fall ist gueltig, der
                 * Hook hat dann nichts zu verteilen. Bis PHP 8.0 ergab strtolower(null) still
                 * "", seit 8.1 ist es eine Deprecation und ab PHP 9 ein TypeError
                 * (000-000-0023). Benannt statt weggecastet: Ein (string)-Cast haette die
                 * Meldung beseitigt und die Frage versteckt, warum hier null ankommt.
                 */
                $controller = $request->get('_controller');
                if (!is_string($controller)) {
                    return;
                }

                $controllerAction = str_replace(':', '.', strtolower($controller));
                if (empty($controllerAction)) {
                    return;
                }
                $controllerParts = explode('.', $controllerAction);
                $app['dispatcher']->dispatch($event, 'pim.controller.after.' . $controllerParts[0] . '.' . $controllerParts[2]);

                $controllerParts = explode('.', $controllerAction);
                $app['dispatcher']->dispatch($event, 'pim.controller.after.' . $controllerParts[0]);
                $app['dispatcher']->dispatch($event, 'pim.controller.after');
            }
        });
    }

    protected function isAuthRequiredForPath($path)
    {
        return !in_array($path, [$this->basePath . self::LOGIN_PATH]);
    }

    protected function checkToken(Request $request, Application $app){

        $tokenString = $request->headers->get(self::TOKEN_HEADER_KEY, null);
        if(empty($tokenString)){
            $tokenString = $request->headers->get(self::TOKEN_HEADER_KEY_ALT, $request->get(self::TOKEN_REQUEST_KEY));
        }
        $headers = $request->headers->all();

        if(empty($tokenString)){
            return false;
        }

        $token = $app['orm.em']->getRepository('Areanet\PIM\Entity\Token')->findOneBy(array('token' => $tokenString));

        if(!$token){
            return false;
        }

        if(!$token->getUser() || !$token->getUser()->getIsActive()){
            return false;
        }

        $tokenTimeout = Adapter::getConfig()->APP_TOKEN_TIMEOUT;

        if(($group = $token->getUser()->getGroup())){
            $tokenTimeout =  $group->getTokenTimeout() * 60;
        }

        if(Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT && !$token->getReferrer() && $tokenTimeout) {
            
            $modified   = $token->getModified()->getTimestamp();
            $now        = new \DateTime();
            $diff       = $now->getTimestamp() - $modified;

            if ($diff > $tokenTimeout) {
                $app['orm.em']->remove($token);
                $app['orm.em']->flush();
                return false;
            }else{
                $token->setModified($now);
                $app['orm.em']->flush();
            }
        }

        $app['auth.user']  = $token->getUser();
        $app['auth.token'] = $token;

        return true;
    }
}