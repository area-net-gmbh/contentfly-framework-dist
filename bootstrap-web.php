<?php
require_once __DIR__.'/bootstrap.php';

use Areanet\PIM\Classes\Controller\Provider\Base\ApiControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\AuthControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\FileControllerProvider;
use Areanet\PIM\Classes\Controller\Provider\Base\SystemControllerProvider;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Exceptions\ContentflyI18NException;
use Areanet\PIM\Classes\Exceptions\FileNotFoundException;
use Areanet\PIM\Classes\Config;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Areanet\PIM\Classes\Security\TrustedProxies;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * `$app['request']` is gone (009-002-0004).
 *
 * The key returned the current request from the stack — and was a trap for exactly that reason:
 * the container remembers a factory's result, so from the first access on it would have returned
 * **the same** request, even after the kernel had cleared it. Under Pimple it was the same, and it
 * killed the error handler in 000-000-0006.
 *
 * In the end nobody read it any more: since 000-000-0006 the error handler takes the request
 * directly from `$app['request_stack']`, and that is the way for everyone else too.
 */

/*
 * TRUSTED PROXIES — BEFORE ANYTHING ELSE (013-001-0003).
 *
 * Up to here `setTrustedProxies()` was not called anywhere in the tree. That had no consequences
 * as long as nobody evaluated the caller's address; with the per-IP login throttle it is the
 * precondition for hitting the right party.
 *
 * It sits at the very top because the setting is applied globally on `Request` and has to be in
 * effect before any request is created — `Application::run()` only builds it at the end of this
 * file.
 *
 * Without an entry in `APP_TRUSTED_PROXIES` nothing happens here, and the application behaves as
 * before.
 */
TrustedProxies::apply(
    Config\Adapter::getConfig()->APP_TRUSTED_PROXIES,
    (string) Config\Adapter::getConfig()->APP_TRUSTED_HEADERS
);

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
 * WEB_ROOT comes from the configuration, no longer from $_SERVER['PHP_SELF'] (000-000-0006).
 *
 * Deriving it from PHP_SELF was correct under Apache with the .htaccess rewrite and nowhere else.
 * Under PHP's built-in server it produced a redirect to `/index.php/file/get/data/files/…` — a
 * path pointing nowhere. File delivery was therefore broken wherever the application did not run
 * behind exactly this .htaccess, and it could not be tested end to end.
 *
 * The default is '/'. A project that mounts the application in a subdirectory sets WEB_ROOT in
 * custom/config.php to '/subdirectory/'. That is something the operator knows and the server can
 * only guess.
 */

/*
 * THE FALLBACK HANDLER IS GONE, AND MEASURABLY SO (009-002-0004).
 *
 * This used to hold `Symfony\Component\Debug\ErrorHandler::register()` and a closure on
 * `ExceptionHandler::setHandler()` that stepped in when an error no longer reached the application
 * at all. It was needed because Silex's ExceptionListenerWrapper accepted `\Exception` as a typed
 * parameter: a TypeError fell through the whole chain down to the global handler (000-000-0006).
 * And because it built its event with `$app['request']`, it died there itself whenever the kernel
 * had already cleared the request.
 *
 * Symfony's HttpKernel catches `\Throwable` and sends every one of them through
 * `kernel.exception`. That leaves the closure no case in which it could step in. The symfony/debug
 * package both classes came from left the tree with 009-002-0001 anyway — rebuilding it with
 * symfony/error-handler would be machinery without a reason.
 *
 * ErrorResponseApiTest proves it: a deliberately triggered TypeError has to arrive as this
 * application's JSON, not as an HTML page.
 */

/*
 * \Throwable, not \Exception (009-002-0004).
 *
 * Silex passed the handler an `\Exception` — it had wrapped any non-exception itself beforehand.
 * Symfony's ExceptionEvent delivers the throwable as it was thrown. If the declaration stayed at
 * `Exception`, a TypeError would strike down the handler with a TypeError — exactly the failure
 * 000-000-0006 fixed, just in a different place.
 */
$app->error(function (\Throwable $e) use($app) {

    if($e instanceof FileNotFoundException){
        return new Response($e->getMessage(), 404, array('X-Status-Code' => 404));
    }else{
        // Not $app['request']: that is a Pimple service that freezes on first access and stays
        // null if the kernel has already cleared the request at this point — and then the error
        // handler dies of the very error it is supposed to report. Exactly this path let a
        // TypeError end as Symfony's "Whoops" page instead of this application's JSON response
        // (000-000-0006). Without a request JSON is assumed: this is an API, the HTML branches
        // below are the special case.
        $request      = (isset($app['request_stack']) && $app['request_stack']) ? $app['request_stack']->getCurrentRequest() : null;
        $contentType  = $request ? (string) $request->headers->get('Content-Type') : 'application/json';

        $accept = AcceptHeader::fromString($contentType);

        /**
         * Without a JSON content type: in debug mode the exception in plain text, otherwise the
         * same JSON response as always (000-000-0006).
         *
         * THIS USED TO SAY `return $app->redirect('/')`. That dates from the time when the PIM UI
         * lived under `/`: a browser that triggered an error somewhere was sent home. The UI went
         * away with Epic 012, which made it a redirect to itself — the application answered
         * `GET /` with a `302` to `/`, endlessly. Measured again and described as expected
         * behaviour in the runbook.
         *
         * There is no home left to send a browser to. Contentfly is an API; whoever sends a
         * request without a content type gets the API's response.
         */
        if(!$accept->has('application/json') && !$accept->has('multipart/form-data')){
            if(Config\Adapter::getConfig()->APP_DEBUG){

                return new Response('<h1>'.$e->getMessage().'</h1><pre>'.$e->getTraceAsString().'</pre>', 500);
            }
        }

        /**
         * The status code (000-000-0006).
         *
         * First `getCode()`, then `getStatusCode()` — in that order, and that is not a matter of
         * taste: `SystemControllerProvider` throws `new AccessDeniedHttpException(…, null, 401)`.
         * The 401 in the third argument is the code the author meant; `getStatusCode()` returns
         * the class's 403 there. Whoever reaches for getStatusCode() first overwrites an explicit
         * value with a generic one — four characterization tests noticed.
         *
         * `getStatusCode()` applies where `getCode()` yields nothing: Symfony's HTTP exceptions have
         * 0 there. `GET /` hit the OPTIONS catch-all and raised a MethodNotAllowedHttpException;
         * the body said "Method Not Allowed", the status code was 500. Now 405.
         *
         * The range check is not just caution: Doctrine puts SQLSTATE values such as '42S02' into
         * `getCode()`, and JsonResponse rejects anything that is not a valid HTTP code — the error
         * response would then be an error itself.
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
            // "status" => ..., not $e->getCode() ?: 500 as a keyless third entry: that used to be
            // here and ended up as "0": 500 in the response. For everything that is neither a
            // ContentflyException nor a ContentflyI18NException — i.e. for every PHP error — the
            // status code in the body was named differently than in the two branches above
            // (000-000-0006).
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

/*
 * The CORS preflight — and the reason `GET /` answers with 405.
 *
 * Before a cross-origin request a browser sends an OPTIONS and expects the Access-Control headers
 * the after hook above sets. This catch-all answers every OPTIONS with 204.
 *
 * It catches **every path**, but only the OPTIONS method. A `GET /` therefore matches it on the
 * path and not on the method — the router reports MethodNotAllowed, the application answers with
 * 405. That is described as expected behaviour in the runbook and checked by
 * SystemControllerApiTest.
 *
 * UNTIL 009-002-0004 IT WAS REGISTERED HERE TWICE, word for word. Under Silex the second
 * registration had no effect — it overwrote the first. It surfaced during the switch; registering a
 * route twice now produces two entries in the RouteCollection, the second of which is never
 * reached.
 */
$app->options("{anything}", function () {
    return new JsonResponse(null, 204);
})->assert("anything", ".*");


/*
 * connect() is called here directly (009-001-0003) — the same change as in the RouteManager. The
 * providers no longer implement Silex's ControllerProviderInterface, so mount() no longer
 * recognises them as such; the finished collection is passed in.
 */
$app->mount('/api',    (new ApiControllerProvider('/api'))->connect($app));
$app->mount('/auth',   (new AuthControllerProvider('/auth'))->connect($app));
$app->mount('/file',   (new FileControllerProvider('/file'))->connect($app));
$app->mount('/system', (new SystemControllerProvider('/system'))->connect($app));

$app->run();