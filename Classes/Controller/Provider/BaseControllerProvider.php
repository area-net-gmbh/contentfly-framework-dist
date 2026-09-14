<?php
namespace Areanet\PIM\Classes\Controller\Provider;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Areanet\PIM\Classes\Kernel\ControllerProviderInterface;
use Areanet\PIM\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseControllerProvider implements ControllerProviderInterface
{

    /*
     * LOGIN_PATH AND isAuthRequiredForPath() HAVE BEEN REMOVED (000-000-0026).
     *
     * The method returned whether a path requires authentication — and nobody called it.
     * Not since `013-002-0004`, but never: `checkToken()` did not use it either. Checked
     * across `lib/`, `custom/`, `plugins/`, `tests/` and `bin/`: apart from its own
     * definition, not a single hit.
     *
     * Removing it is more than tidying up. When reading, it looked like the place where you
     * control which paths are open — and it does not do that. That decision is made per
     * route: via `isSecure` in the `RouteManager`, or via the providers' `$checkAuth` hook. A
     * method that pretends to be a switch that exists elsewhere is more dangerous than none
     * at all.
     *
     * The breaking change is recorded in an_project/docs/breaking-changes.md.
     */

    /*
     * THE THREE TOKEN CONSTANTS HAVE BEEN REMOVED (013-002-0004).
     *
     * TOKEN_HEADER_KEY ('appcms-token'), TOKEN_HEADER_KEY_ALT ('X-XSRF-TOKEN') and
     * TOKEN_REQUEST_KEY ('_token') lived here because `checkToken()` read them. The method no
     * longer exists; the values now live in `Areanet\PIM\Classes\Security\TokenSources`,
     * together with the code that uses them.
     *
     * Leaving them here would be worse than removing them: three public constants that no
     * longer control anything look, on the next read, like the place where you change the
     * TokenSources. The breaking change is recorded in
     * an_project/docs/breaking-changes.md.
     */

    protected $basePath = '';

    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }


    protected function setUpMiddleware(Application $app)
    {
        $app->before(function (Request $request)use ($app) {

            // Not installed: this used to redirect to the installer screen. That no longer
            // exists - installation happens on the command line. Instead of a redirect to
            // nowhere, we say what needs to be done.
            if (Adapter::getConfig()->DB_HOST == '$SET_DB_HOST') {
                return new \Symfony\Component\HttpFoundation\JsonResponse(array(
                    'message' => 'Contentfly is not installed. Run the installation: php bin/console.php appcms:install'
                ), 503);
            }

            /*
             * THIS IS WHERE THE PAYLOAD LANDS — and that is why the controllers read it from
             * `$request->request` (009-003-0001).
             *
             * The JSON body is decoded and put into the request bag. Up to Symfony 7.4 the
             * code read it with `$request->get()`, which searches `attributes`, `query` and
             * `request` in that order; the method is now deprecated and is removed in Symfony
             * 8. It was replaced at each call site by the source that is actually meant — 73
             * times `request`, four times `attributes` for `_controller`, which the router
             * sets.
             */
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

            if(!is_object($request->attributes->get('_controller'))) {
                $event = new \Areanet\PIM\Classes\Event();
                $event->setParam('request', $request);
                $event->setParam('app', $app);
                /*
                 * `_controller` is missing entirely for internal requests — the case is valid,
                 * the hook then has nothing to dispatch. Up to PHP 8.0, strtolower(null)
                 * silently returned "", since 8.1 it is a deprecation and from PHP 9 on a
                 * TypeError (000-000-0023). Named instead of cast away: a (string) cast would
                 * have removed the notice and hidden the question of why null arrives here.
                 */
                $controller = $request->attributes->get('_controller');
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

            if(!is_object($request->attributes->get('_controller'))) {
                $event = new \Areanet\PIM\Classes\Event();
                $event->setParam('request', $request);
                $event->setParam('response', $response);
                $event->setParam('app', $app);

                /*
                 * `_controller` is missing entirely for internal requests — the case is valid,
                 * the hook then has nothing to dispatch. Up to PHP 8.0, strtolower(null)
                 * silently returned "", since 8.1 it is a deprecation and from PHP 9 on a
                 * TypeError (000-000-0023). Named instead of cast away: a (string) cast would
                 * have removed the notice and hidden the question of why null arrives here.
                 */
                $controller = $request->attributes->get('_controller');
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

    /**
     * Authenticates the request — via Symfony's `access_token` authenticator (013-002-0004).
     *
     * `checkToken()` HAS BEEN REMOVED. The method read the token from four sources, looked it
     * up in `pim_token`, checked user and timeout and wrote `modified` back — all in one body.
     * The same is now spread across three classes, each testable on its own:
     *
     *   TokenSources     where a token may come from, in the same order as before
     *   TokenHandler     the branching: JWT or `pim_token`
     *   TokenAuthenticator   runs the authenticator and catches every failure right away
     *
     * WHAT REMAINS IS THE OUTWARD INTERFACE: a `bool`, and on success `$app['auth.user']` and
     * `$app['auth.token']` are set as before. Contentfly's own permission model reads them in
     * dozens of places; replacing it with Symfony roles is explicitly not part of this story.
     *
     * `$app['auth.token']` CAN NOW BE NULL. In the JWT branch there is no row in `pim_token` —
     * that is the whole benefit of this branch. The key is only read on logout, and the case
     * is handled there.
     */
    protected function authenticate(Request $request, Application $app): bool
    {
        $user = $app['tokenAuthenticator']->user($request);

        if (!$user instanceof User) {
            return false;
        }

        $app['auth.user']  = $user;
        $app['auth.token'] = $app['tokenHandler']->lastToken();

        return true;
    }
}
