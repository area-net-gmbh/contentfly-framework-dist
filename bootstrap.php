<?php
const ROOT_DIR = __DIR__ . '/../..';

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
use Areanet\PIM\Command\InstallCommand;
use Areanet\PIM\Command\SetupCommand;
use Areanet\PIM\Classes\ORM\EntityManagerFactory;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ApcuCache;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Events;
use Knp\Console\ConsoleEvent;
use Knp\Console\ConsoleEvents;
use Silex\Application;
use Knp\Provider\ConsoleServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/Config.php');
AnnotationRegistry::registerFile(ROOT_DIR.'/lib/contentfly/Classes/Annotations/ManyToMany.php');

if(Adapter::getConfig()->APP_DEBUG){
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL ^E_NOTICE^E_DEPRECATED);
}

$app = new Application();

$app['is_installed'] = (Adapter::getConfig()->DB_HOST != '$SET_DB_HOST');
$app['auth.user'] = null;

Adapter::setHostname(HOST);
date_default_timezone_set(Adapter::getConfig()->APP_TIMEZONE);

$app->register(new Silex\Provider\ServiceControllerServiceProvider());


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
                // Ohne den Port landet die Verbindung immer auf 3306 - und zwar still,
                // also auf irgendeiner MySQL, die dort zufaellig lauscht (Task 000-000-0004).
                'port' => Adapter::getConfig()->DB_PORT,
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
    // Ersetzt dflydev/doctrine-orm-service-provider (006-002-0005). Der Provider ist seit
    // 2018 unverändert und benutzt einen Namensraum, den doctrine/persistence 2.0 verschoben
    // hat — er blockierte damit jedes PHP-8-taugliche ORM. Uebergangsloesung bis Epic 009.
    $app['orm.em'] = function ($app) {
        return EntityManagerFactory::erzeugen(
            $app['dbs']['pim'],
            array(
                array('namespace' => 'Areanet\PIM\Entity', 'path' => ROOT_DIR . '/lib/contentfly/Entity'),
                array('namespace' => 'Custom\Entity',       'path' => ROOT_DIR . '/custom/Entity'),
            ),
            ROOT_DIR . '/data/cache/doctrine',
            (bool) Adapter::getConfig()->APP_AUTOGENERATE_PROXIES,
            array('Find_In_Set' => '\Areanet\PIM\Classes\ORM\Query\Mysql\FindInSet')
        );
    };

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
        $app->add(new InstallCommand());
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

    header("Strict-Transport-Security:max-age=63072000");
}

require_once ROOT_DIR.'/custom/app.php';

$app['routeManager']->bindRoutes();
