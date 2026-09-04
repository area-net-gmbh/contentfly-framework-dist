<?php
const ROOT_DIR = __DIR__ . '/../..';

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

require_once ROOT_DIR.'/lib/contentfly/version.php';
require_once ROOT_DIR.'/vendor/autoload.php';
if(file_exists(ROOT_DIR.'/custom/vendor/autoload.php')){
    require_once ROOT_DIR.'/custom/vendor/autoload.php';
}

require_once ROOT_DIR.'/custom/config.php';
require_once ROOT_DIR.'/custom/version.php';

define('HOST', $_SERVER["SERVER_NAME"] ?? 'default');

use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Auth;
use Areanet\PIM\Classes\Mailer;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Events\LoadMetadata;
use Areanet\PIM\Classes\Helper;
use Areanet\PIM\Classes\Manager\ConsoleManager;
use Areanet\PIM\Classes\Manager\PluginManager;
use Areanet\PIM\Classes\Manager\RouteManager;
use Areanet\PIM\Classes\Manager\TypeManager;
use Areanet\PIM\Classes\ORM\Mapping\ContentflyQuoteStrategy;
use Areanet\PIM\Command\SetupCommand;
use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ApcuCache;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Events;
use Knp\Console\ConsoleEvent;
use Knp\Console\ConsoleEvents;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use Silex\Application;
use Knp\Provider\ConsoleServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/Config.php');
AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/ManyToMany.php');
AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/MatrixChooser.php');

if(Adapter::getConfig()->APP_DEBUG){
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL ^E_NOTICE^E_DEPRECATED);
}

$app = new Application();
$app->register(new Silex\Provider\SessionServiceProvider());

$app['is_installed'] = (Adapter::getConfig()->DB_HOST != '$SET_DB_HOST');
$app['auth.user'] = null;

Adapter::setHostname(HOST);
date_default_timezone_set(Adapter::getConfig()->APP_TIMEZONE);

$app->register(new Silex\Provider\ServiceControllerServiceProvider());

define('APP_CMS_SHOW_ID_IN_LIST', Adapter::getConfig()->FRONTEND_SHOW_ID_IN_LIST);
define('APP_CMS_SHOW_OWNER_IN_LIST', Adapter::getConfig()->FRONTEND_SHOW_OWNER_IN_LIST);

if(Adapter::getConfig()->APP_LANGUAGES){
    define('APP_CMS_MAIN_LANG', Adapter::getConfig()->APP_LANGUAGES[0]);
}else{
    define('APP_CMS_MAIN_LANG', null);
}

if($app['is_installed']) {
    if (Adapter::getConfig()->DB_GUID_STRATEGY) {
        define('APPCMS_ID_TYPE', 'string');
        define('APPCMS_ID_STRATEGY', 'UUID');
    } else {
        define('APPCMS_ID_TYPE', Adapter::getConfig()->DB_ID_INTEGER_TYPE);
        define('APPCMS_ID_STRATEGY', 'AUTO');
    }

    $app->register(new Silex\Provider\DoctrineServiceProvider(), array(
        'dbs.options' => array(
            'pim' => array(
                'driver' => 'pdo_mysql',
                'host' => Adapter::getConfig()->DB_HOST,
                'dbname' => Adapter::getConfig()->DB_NAME,
                'user' => Adapter::getConfig()->DB_USER,
                'password' => Adapter::getConfig()->DB_PASS,
                'charset' => Adapter::getConfig()->DB_CHARSET,
                'defaultTableOptions' => array(
                    'charset' => Adapter::getConfig()->DB_CHARSET,
                    'collate' => Adapter::getConfig()->DB_COLLATE
                )

            )
        ),
    ));
}

$app->register(new ConsoleServiceProvider(), array(
    'console.name'              => 'PIM',
    'console.version'           => APP_VERSION,
    'console.project_directory' => ROOT_DIR
));

$app['helper'] = function () {
    return new Helper();
};

$app['auth'] = function ($app) {
    return new Auth($app);
};

/** @var \PHPMailer\PHPMailer\PHPMailer */

$app['mailer'] = function ($app) {
    return (new Mailer($app))->mail;
};

if($app['is_installed']) {
    $app->register(new DoctrineOrmServiceProvider(), array(
        'orm.proxies_dir' => ROOT_DIR . '/data/cache/doctrine',
        'orm.em.options' => array(
            'connection' => 'pim',
            'mappings' => array(
                array(
                    'type' => 'annotation',
                    'namespace' => 'Areanet\PIM\Entity',
                    'path' => ROOT_DIR . '/lib/contentfly/Entity',
                    'use_simple_annotation_reader' => false
                ),
                array(
                    'type' => 'annotation',
                    'namespace' => 'Custom\Entity',
                    'path' => ROOT_DIR . '/custom/Entity',
                    'use_simple_annotation_reader' => false
                )
            )
        ),
        'orm.auto_generate_proxies' => Adapter::getConfig()->APP_AUTOGENERATE_PROXIES,
        'orm.custom.functions.numeric' => array(
            'Find_In_Set' => '\Areanet\PIM\Classes\ORM\Query\Mysql\FindInSet'
        )
    ));

    $config = $app['orm.em']->getConfiguration();
    $config->setQuoteStrategy(new ContentflyQuoteStrategy());

    if (!Adapter::getConfig()->APP_DEBUG && !defined('APPCMS_CONSOLE')) {
        switch (Adapter::getConfig()->APP_CACHE_DRIVER) {
            case 'apc':
                $config->setQueryCacheImpl(new ApcCache('query'));
                $config->setMetadataCacheImpl(new ApcCache('metadata'));
                break;
            case 'apcu':
                $config->setQueryCacheImpl(new ApcuCache('query'));
                $config->setMetadataCacheImpl(new ApcuCache('metadata'));
                break;
            case 'memcached':
                $cache = new MemcachedCache();
                $cache->setMemcached(new Memcached());

                $config->setQueryCacheImpl($cache);
                $config->setMetadataCacheImpl($cache);
                break;
            case 'filesystem':
            default:
                $config->setQueryCacheImpl(new FilesystemCache(ROOT_DIR . '/data/cache/query'));
                $config->setMetadataCacheImpl(new FilesystemCache(ROOT_DIR . '/data/cache/metadata'));
                break;
        }
    }

    $app['typeManager'] = function ($app) {
        return new TypeManager($app);
    };

    /** @return PluginManager */
    $app['pluginManager'] = function ($app) {
        return new PluginManager($app);
    };

    foreach (Adapter::getConfig()->APP_SYSTEM_TYPES as $systemType) {
        if(!class_exists($systemType)){
            die("contentfly_type_class_not_found: $systemType");
        }

        $typeClass = new $systemType($app);
        $app['typeManager']->registerType($typeClass);
    }
}else{
    $app['orm.em'] = null;
}

if(!is_dir(ROOT_DIR.'/custom/Views/')){
    mkdir(ROOT_DIR.'/custom/Views/');
}

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path'     =>   array(ROOT_DIR.'/custom/Views/', ROOT_DIR.'/lib/contentfly-ui/'),
    'twig.options'  => array('strict_variables' => false)
));

if($app['is_installed']) {
    $app['thumbnailSettings'] = function ($app) {
        try {
            $queryBuilder = $app['orm.em']->createQueryBuilder();
            $queryBuilder
                ->select('thumbnailSetting')
                ->from('Areanet\PIM\Entity\ThumbnailSetting', 'thumbnailSetting');
            $query = $queryBuilder->getQuery();
            return $query->getResult();
        } catch (Exception) {
            return array();
        }
    };

    foreach (Adapter::getConfig()->FILE_PROCESSORS as $fileProcessorSetting) {
        $fileProcessor = new $fileProcessorSetting();

        foreach ($app['thumbnailSettings'] as $thumbnailSetting) {
            $fileProcessor->registerImageSize($thumbnailSetting);
        }

        Areanet\PIM\Classes\File\Processing::registerProcessor($fileProcessor);
    }
}

$app['debug'] = Adapter::getConfig()->APP_DEBUG;

$app['consoleManager'] = function ($app) {
    return new ConsoleManager($app);
};

$app['routeManager'] = function ($app) {
    return new RouteManager($app);
};

$app->extend('dispatcher', function (EventDispatcherInterface $dispatcher, $app) {
    $dispatcher->addListener(ConsoleEvents::INIT, function (ConsoleEvent $event) {
        $app = $event->getApplication();
        $app->add(new SetupCommand());
    });
    return $dispatcher;
});

$app['schema'] = function ($app){
    $api = new Api($app);
    return $api->getSchema();
};

$app['database'] = function ($app){
    $config = Adapter::getConfig();

    $connectionParams = array(
        'dbname'    => $config->DB_NAME,
        'user'      => $config->DB_USER,
        'password'  => $config->DB_PASS,
        'port'      => $config->DB_PORT,
        'host'      => $config->DB_HOST,
        'driver'    => 'pdo_mysql',
        'charset'   => $config->DB_CHARSET
    );

    return  DriverManager::getConnection($connectionParams);
};

if($app['is_installed']) {
    $evm = $app['orm.em']->getEventManager();
    $evm->addEventListener(Events::loadClassMetadata, new LoadMetadata());
}

$app->register(new Silex\Provider\ValidatorServiceProvider());

if(Adapter::getConfig()->APP_FORCE_SSL && !defined('APPCMS_CONSOLE')){
    if ( !(isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' ||
            $_SERVER['HTTPS'] == 1) ||
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'))
    {
        $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $redirect);
        exit();
    }

    ini_set('session.cookie_secure', 1);
    header("Strict-Transport-Security:max-age=63072000");
}

if (Adapter::getConfig()->USE_SCSS_COMPILER) {
    $scssFile = ROOT_DIR . '/custom/Frontend/scss/'.Adapter::getConfig()->BASE_SCSS_FILE;

    if (file_exists($scssFile)) {
        $cssPath = ROOT_DIR . '/custom/Frontend/css/';
        $cssFile = $cssPath . basename($scssFile, '.scss') . '.css';
        $mapFile = $cssFile . '.map';
        $hashFile = $cssPath . 'css_cache_hash.txt';

        $hash = md5_file($scssFile);
        $scssContent = file_get_contents($scssFile);

        preg_match_all('/@import\s*[\'"](.+?)[\'"]\s*;/', $scssContent, $matches);

        foreach ($matches[1] as $import) {
            $importPath = ROOT_DIR . '/custom/Frontend/scss/' . trim($import, '\'"');

            $importFile = $importPath . '.scss';
            $partialFile = dirname($importFile) . '/_' . basename($importFile);

            if (file_exists($partialFile)) {
                $hash .= md5_file($partialFile);
            } elseif (file_exists($importFile)) {
                $hash .= md5_file($importFile);
            }
        }

        $currentHash = md5($hash);
        $storedHash = file_exists($hashFile) ? file_get_contents($hashFile) : '';

        if (!file_exists($cssFile) || $currentHash !== $storedHash) {
            if (!is_dir($cssPath)) {
                mkdir($cssPath, 0777, true);
            }

            $scss = new Compiler();
            $scss->setImportPaths(ROOT_DIR . '/custom/Frontend/scss/');

            $scss->setSourceMap(Compiler::SOURCE_MAP_FILE);
            $scss->setSourceMapOptions([
                'sourceMapURL'      => basename($mapFile),
                'sourceMapFilename' => basename($cssFile),
                'sourceMapBasepath' => realpath(ROOT_DIR),
                'sourceRoot'        => '/'
            ]);

            $scss->setOutputStyle(OutputStyle::COMPRESSED);
            $scssResult = $scss->compileString($scssContent, $scssFile);

            file_put_contents($mapFile, $scssResult->getSourceMap());
            file_put_contents($cssFile, $scssResult->getCss());
            file_put_contents($hashFile, $currentHash);
        }
    }
}

$app['auth']->init();

require_once ROOT_DIR.'/custom/app.php';

$app['routeManager']->bindRoutes();
