<?php
require_once __DIR__.'/bootstrap.php';

use Areanet\PIM\Classes\Controller\Provider\Base\ApiControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\AuthControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\FileControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\SystemControllerProvider;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Exceptions\ContentflyI18NException;
use Areanet\PIM\Classes\Exceptions\FileNotFoundException;
use Areanet\PIM\Controller;
use Areanet\PIM\Classes\Config;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app['request'] = function()use ($app){
    return $app['request_stack'] ? $app['request_stack']->getCurrentRequest() : null;
};

header("Content-Security-Policy: ".Config\Adapter::getConfig()->APP_CS_POLICY);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");

if(Config\Adapter::getConfig()->APP_HTTP_AUTH_USER) {
    if(!isset($_SERVER['PHP_AUTH_USER'])) {
        $authString = empty($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : $_SERVER['HTTP_AUTHORIZATION'];
        list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', base64_decode(substr($authString, 6)));
    }

    if (empty($_SERVER['PHP_AUTH_USER'])) {
        header('WWW-Authenticate: Basic realm="APP-CMS Authentification"');
        header('HTTP/1.0 401 Unauthorized');
        exit;
    } else {
        if ($_SERVER['PHP_AUTH_USER'] != Config\Adapter::getConfig()->APP_HTTP_AUTH_USER && $_SERVER['PHP_AUTH_PW'] != Config\Adapter::getConfig()->APP_HTTP_AUTH_PASS) {
            header('WWW-Authenticate: Basic realm="APP-CMS Authentification"');
            header('HTTP/1.0 401 Unauthorized');
            exit;
        }
    }
}

/*
 * WEB_ROOT kommt aus der Konfiguration, nicht mehr aus $_SERVER['PHP_SELF'] (000-000-0006).
 *
 * Die Ableitung aus PHP_SELF stimmte unter Apache mit der .htaccess-Rewrite und sonst nirgends.
 * Unter dem eingebauten PHP-Server ergab sie einen Redirect auf
 * `/index.php/file/get/data/files/…` — einen Pfad, der ins Leere zeigt. Damit war die
 * Dateiauslieferung ueberall dort kaputt, wo die Anwendung nicht hinter genau dieser
 * .htaccess laeuft, und in den Tests nicht end-to-end pruefbar.
 *
 * Der Vorgabewert ist '/'. Ein Projekt, das die Anwendung in einem Unterverzeichnis mountet,
 * setzt WEB_ROOT in custom/config.php auf '/unterverzeichnis/'. Das ist eine Angabe, die der
 * Betreiber kennt und der Server nur raten kann.
 */

ErrorHandler::register();

$handler = Symfony\Component\Debug\ExceptionHandler::register($app['debug']);
/**
 * Die letzte Instanz — und sie darf nicht selbst sterben (000-000-0006).
 *
 * Diese Closure laeuft nur fuer das, was die Anwendung gar nicht mehr erreicht. Vorher baute sie
 * das Ereignis mit `$app['request']`, und wenn der Kernel den Request schon abgeraeumt hat, ist
 * das null. Dann stirbt der Nothelfer an einem TypeError, Symfony faengt DEN und zeigt seine
 * "Whoops"-Seite. Der urspruengliche Fehler ist damit verloren — genau in dem Moment, in dem man
 * ihn braeuchte. Im Serverlog stand nur noch die Beschwerde ueber das null-Argument.
 *
 * Jetzt: Request aus dem Stack, notfalls aus den Globals; und wenn niemand eine Antwort setzt,
 * eine eigene, statt `null->sendHeaders()`.
 */
$handler->setHandler(function ($exception) use ($app) {

    $request = null;
    if (isset($app['request_stack']) && $app['request_stack']) {
        $request = $app['request_stack']->getCurrentRequest();
    }
    if (!$request) {
        $request = Request::createFromGlobals();
    }

    $event = new Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent(
        $app,
        $request,
        Symfony\Component\HttpKernel\HttpKernelInterface::MASTER_REQUEST,
        $exception
    );

    $app['dispatcher']->dispatch(Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, $event);

    $response = $event->getResponse();
    if (!$response) {
        $response = new JsonResponse(array(
            'message' => $exception->getMessage(),
            'type'    => get_class($exception),
            'status'  => 500
        ), 500);
    }

    $response->sendHeaders();
    $response->sendContent();
});

$app->error(function (Exception $e) use($app) {

    if($e instanceof FileNotFoundException){
        return new Response($e->getMessage(), 404, array('X-Status-Code' => 404));
    }else{
        // Nicht $app['request']: Das ist ein Pimple-Service, der beim ersten Zugriff einfriert
        // und null bleibt, wenn der Kernel den Request zu diesem Zeitpunkt schon abgeraeumt
        // hat — und dann stirbt der Fehlerhandler an dem Fehler, den er melden soll. Genau
        // dieser Weg hat einen TypeError als Symfonys "Whoops"-Seite enden lassen statt als
        // JSON-Antwort dieser Anwendung (000-000-0006). Ohne Request wird von JSON
        // ausgegangen: Dies ist eine API, die HTML-Zweige unten sind der Sonderfall.
        $request      = (isset($app['request_stack']) && $app['request_stack']) ? $app['request_stack']->getCurrentRequest() : null;
        $contentType  = $request ? (string) $request->headers->get('Content-Type') : 'application/json';

        $accept = AcceptHeader::fromString($contentType);

        /**
         * Ohne JSON-Content-Type: im Debug-Modus die Ausnahme im Klartext, sonst dieselbe
         * JSON-Antwort wie sonst auch (000-000-0006).
         *
         * VORHER STAND HIER `return $app->redirect('/')`. Das stammt aus der Zeit, als unter
         * `/` die PIM-Oberflaeche lag: Ein Browser, der irgendwo einen Fehler ausloeste, wurde
         * nach Hause geschickt. Die Oberflaeche ist mit Epic 012 entfallen, und damit war es
         * eine Umleitung auf sich selbst — `GET /` beantwortete die Anwendung mit `302` nach
         * `/`, endlos. Nachgemessen und im Runbook als erwartetes Verhalten beschrieben.
         *
         * Es gibt kein Zuhause mehr, in das man einen Browser schicken koennte. Contentfly ist
         * eine API; wer ohne Content-Type anfragt, bekommt die Antwort der API.
         */
        if(!$accept->has('application/json') && !$accept->has('multipart/form-data')){
            if(Config\Adapter::getConfig()->APP_DEBUG){

                return new Response('<h1>'.$e->getMessage().'</h1><pre>'.$e->getTraceAsString().'</pre>', 500);
            }
        }

        /**
         * Der Statuscode (000-000-0006).
         *
         * Erst `getCode()`, dann `getStatusCode()` — in dieser Reihenfolge, und das ist keine
         * Geschmacksfrage: `SystemControllerProvider` wirft
         * `new AccessDeniedHttpException('Zugriff verweigert', null, 401)`. Die 401 im dritten
         * Argument ist der Code, den der Autor gemeint hat; `getStatusCode()` liefert dort die
         * 403 der Klasse. Wer zuerst nach getStatusCode() greift, ueberschreibt eine
         * ausdrueckliche Angabe mit einer allgemeinen — vier Charakterisierungstests haben das
         * bemerkt.
         *
         * `getStatusCode()` greift, wo `getCode()` nichts hergibt: Die HTTP-Ausnahmen von
         * Symfony haben dort 0. `GET /` traf den OPTIONS-Catch-All und loeste eine
         * MethodNotAllowedHttpException aus; im Rumpf stand "Method Not Allowed", der
         * Statuscode war 500. Jetzt 405.
         *
         * Die Bereichspruefung ist nicht nur Vorsicht: Doctrine setzt in `getCode()`
         * SQLSTATE-Werte wie '42S02', und JsonResponse weist alles zurueck, was kein gueltiger
         * HTTP-Code ist — die Fehlerantwort waere dann selbst ein Fehler.
         */
        $code   = $e->getCode();
        $status = (is_int($code) && $code >= 100 && $code <= 599)
            ? $code
            : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);

        if($e instanceof ContentflyException){
            $data = array('message' => $e->getMessage(), 'type' => get_class($e), 'message_value' => $e->getValue(), 'status' => $e->getCode());
        }elseif($e instanceof ContentflyI18NException){
            $data = array('message' => $e->getMessage(), 'type' => get_class($e), 'message_entity' => $e->getEntity(), 'message_lang' => $e->getLang(), 'status' => $e->getCode());
        }else{
            // "status" => ..., nicht $e->getCode() ?: 500 als schluessellosen dritten Eintrag:
            // Der stand vorher da und landete als "0": 500 in der Antwort. Fuer alles, was
            // weder ContentflyException noch ContentflyI18NException ist — also fuer jeden
            // PHP-Fehler — hiess der Statuscode im Rumpf anders als in den beiden Zweigen
            // darueber (000-000-0006).
            $data = array("message" => $e->getMessage(), "type" => get_class($e), "status" => $status);
        }

        if(Config\Adapter::getConfig()->APP_DEBUG){
            $data['debug'] = $e->getTrace();
        }

        return $app->json($data, $status);
    }

});

$app->after(function (Request $request, Response $response) {

    $response->headers->set('Access-Control-Allow-Origin', $request->headers->get('Origin'));
    $response->headers->set('Access-Control-Allow-Credentials', Config\Adapter::getConfig()->APP_ALLOW_CREDENTIALS_SDK);

    $response->headers->set('Access-Control-Allow-Headers', Config\Adapter::getConfig()->APP_ALLOW_HEADERS_SDK);
    $response->headers->set('Access-Control-Allow-Methods', Config\Adapter::getConfig()->APP_ALLOW_METHODS);
    $response->headers->set('Access-Control-Max-Age', Config\Adapter::getConfig()->APP_MAX_AGE);

});

$app->options("{anything}", function () {
    return new JsonResponse(null, 204);
})->assert("anything", ".*");


$app->options("{anything}", function () {
    return new JsonResponse(null, 204);
})->assert("anything", ".*");


/*
 * connect() wird selbst gerufen (009-001-0003) — dieselbe Aenderung wie im RouteManager.
 * Die Provider implementieren nicht mehr Silex' ControllerProviderInterface, also erkennt
 * mount() sie nicht mehr als solche; uebergeben wird die fertige Sammlung.
 */
$app->mount('/api',    (new ApiControllerProvider('/api'))->connect($app));
$app->mount('/auth',   (new AuthControllerProvider('/auth'))->connect($app));
$app->mount('/file',   (new FileControllerProvider('/file'))->connect($app));
$app->mount('/system', (new SystemControllerProvider('/system'))->connect($app));

$app->run();