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
     * LOGIN_PATH UND isAuthRequiredForPath() SIND ENTFALLEN (000-000-0026).
     *
     * Die Methode gab zurueck, ob ein Pfad eine Anmeldung braucht — und niemand rief sie.
     * Nicht seit `013-002-0004`, sondern nie: Auch `checkToken()` hat sie nicht benutzt.
     * Nachgemessen ueber `lib/`, `custom/`, `plugins/`, `tests/` und `bin/`: ausser ihrer
     * eigenen Definition kein einziger Treffer.
     *
     * Sie zu entfernen ist mehr als Aufraeumen. Beim Lesen sah sie aus wie die Stelle, an der
     * man steuert, welche Pfade offen sind — und das tut sie nicht. Diese Entscheidung faellt
     * je Route: ueber `isSecure` im `RouteManager`, beziehungsweise ueber den
     * `$checkAuth`-Hook der Provider. Eine Methode, die einen Schalter vortaeuscht, den es
     * woanders gibt, ist gefaehrlicher als gar keine.
     *
     * Die Bruchstelle steht in an_project/docs/breaking-changes.md.
     */

    /*
     * DIE DREI TOKEN-KONSTANTEN SIND ENTFALLEN (013-002-0004).
     *
     * TOKEN_HEADER_KEY ('appcms-token'), TOKEN_HEADER_KEY_ALT ('X-XSRF-TOKEN') und
     * TOKEN_REQUEST_KEY ('_token') standen hier, weil `checkToken()` sie las. Die Methode gibt
     * es nicht mehr; die Werte stehen jetzt in `Areanet\PIM\Classes\Security\TokenSources`,
     * zusammen mit dem Code, der sie benutzt.
     *
     * Sie hier stehen zu lassen waere schlimmer als sie zu entfernen: Drei oeffentliche
     * Konstanten, die nichts mehr steuern, sehen beim naechsten Lesen aus wie die Stelle, an
     * der man die TokenSources aendert. Die Bruchstelle steht in
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

            // Nicht installiert: Frueher fuehrte hier ein Redirect auf die Installer-Maske.
            // Die gibt es nicht mehr - installiert wird auf der Kommandozeile. Statt eines
            // Redirects ins Leere sagen wir, was zu tun ist.
            if (Adapter::getConfig()->DB_HOST == '$SET_DB_HOST') {
                return new \Symfony\Component\HttpFoundation\JsonResponse(array(
                    'message' => 'Contentfly ist nicht installiert. Installation ausfuehren: php bin/console.php appcms:install'
                ), 503);
            }

            /*
             * HIER LANDET DIE NUTZLAST — und deshalb lesen die Controller sie aus
             * `$request->request` (009-003-0001).
             *
             * Der JSON-Rumpf wird dekodiert und in den request-Beutel gelegt. Bis Symfony 7.4
             * las der Code ihn mit `$request->get()`, das der Reihe nach `attributes`, `query`
             * und `request` durchsucht; die Methode ist jetzt deprecated und faellt in Symfony
             * 8 weg. Ersetzt wurde sie je Aufrufstelle durch die Quelle, die tatsaechlich
             * gemeint ist — 73 Mal `request`, viermal `attributes` fuer `_controller`, das der
             * Router setzt.
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
                 * `_controller` fehlt bei internen Anfragen ganz — der Fall ist gueltig, der
                 * Hook hat dann nichts zu verteilen. Bis PHP 8.0 ergab strtolower(null) still
                 * "", seit 8.1 ist es eine Deprecation und ab PHP 9 ein TypeError
                 * (000-000-0023). Benannt statt weggecastet: Ein (string)-Cast haette die
                 * Meldung beseitigt und die Frage versteckt, warum hier null ankommt.
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
                 * `_controller` fehlt bei internen Anfragen ganz — der Fall ist gueltig, der
                 * Hook hat dann nichts zu verteilen. Bis PHP 8.0 ergab strtolower(null) still
                 * "", seit 8.1 ist es eine Deprecation und ab PHP 9 ein TypeError
                 * (000-000-0023). Benannt statt weggecastet: Ein (string)-Cast haette die
                 * Meldung beseitigt und die Frage versteckt, warum hier null ankommt.
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
     * Meldet den Request an — ueber Symfonys `access_token`-Authenticator (013-002-0004).
     *
     * `checkToken()` IST ENTFALLEN. Die Methode las den Token aus vier Quellen, schlug ihn in
     * `pim_token` nach, prueste Benutzer und Timeout und schrieb `modified` zurueck — alles in
     * einem Rumpf. Dasselbe steht jetzt auf drei Klassen verteilt, jede fuer sich pruefbar:
     *
     *   TokenSources     woher ein Token kommen darf, in der Reihenfolge von frueher
     *   TokenHandler     die Verzweigung: JWT oder `pim_token`
     *   TokenAuthenticator   faehrt den Authenticator und faengt jeden Fehlschlag gleich ab
     *
     * WAS BLEIBT, IST DIE SCHNITTSTELLE NACH AUSSEN: ein `bool`, und im Erfolgsfall stehen
     * `$app['auth.user']` und `$app['auth.token']` wie bisher. Das Contentfly-eigene
     * Berechtigungsmodell liest sie an Dutzenden Stellen; es durch Symfony-Rollen zu ersetzen
     * ist ausdruecklich nicht Teil dieser Story.
     *
     * `$app['auth.token']` KANN JETZT NULL SEIN. Im JWT-Zweig gibt es keine Zeile in
     * `pim_token` — das ist der ganze Gewinn dieses Zweigs. Gelesen wird der Schluessel nur
     * beim Abmelden, und der Fall ist dort behandelt.
     */
    protected function anmelden(Request $request, Application $app): bool
    {
        $benutzer = $app['tokenAuthenticator']->user($request);

        if (!$benutzer instanceof User) {
            return false;
        }

        $app['auth.user']  = $benutzer;
        $app['auth.token'] = $app['tokenHandler']->lastToken();

        return true;
    }
}
