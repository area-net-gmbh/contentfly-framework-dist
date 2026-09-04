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

Config\Adapter::getConfig()->WEB_ROOT = dirname($_SERVER['PHP_SELF']) == '/' ? dirname($_SERVER['PHP_SELF']) : dirname($_SERVER['PHP_SELF']).'/';

ErrorHandler::register();

$handler = Symfony\Component\Debug\ExceptionHandler::register($app['debug']);
$handler->setHandler(function ($exception) use ($app) {

    // Create an ExceptionEvent with all the informations needed.
    $event = new Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent(
        $app,
        $app['request'],
        Symfony\Component\HttpKernel\HttpKernelInterface::MASTER_REQUEST,
        $exception
    );

    // Hey Silex ! We have something for you, can you handle it with your exception handler ?
    $app['dispatcher']->dispatch(Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, $event);

    // And now, just display the response ;)
    $response = $event->getResponse();
    $response->sendHeaders();
    $response->sendContent();
});

$app->error(function (Exception $e) use($app) {

    if($e instanceof FileNotFoundException){
        return new Response($e->getMessage(), 404, array('X-Status-Code' => 404));
    }else{
        $accept = AcceptHeader::fromString($app["request"]->headers->get('Content-Type'));

        if(!$accept->has('application/json') && !$accept->has('multipart/form-data')){
            if(Config\Adapter::getConfig()->APP_DEBUG){

                return new Response('<h1>'.$e->getMessage().'</h1><pre>'.$e->getTraceAsString().'</pre>', 500);
            }else {
                return $app->redirect('/');
            }
        }

        if($e instanceof ContentflyException){
            $data = array('message' => $e->getMessage(), 'type' => get_class($e), 'message_value' => $e->getValue(), 'status' => $e->getCode());
        }elseif($e instanceof ContentflyI18NException){
            $data = array('message' => $e->getMessage(), 'type' => get_class($e), 'message_entity' => $e->getEntity(), 'message_lang' => $e->getLang(), 'status' => $e->getCode());
        }else{
            $data = array("message" => $e->getMessage(), "type" => get_class($e), $e->getCode() ?: 500);
        }

        if(Config\Adapter::getConfig()->APP_DEBUG){
            $data['debug'] = $e->getTrace();
        }

        return $app->json($data, $e->getCode() ?: 500);
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


$app->mount('/api', new ApiControllerProvider('/api'));
$app->mount('/auth', new AuthControllerProvider('/auth'));
$app->mount('/file', new FileControllerProvider('/file'));
$app->mount('/system', new SystemControllerProvider('/system'));

$app->run();