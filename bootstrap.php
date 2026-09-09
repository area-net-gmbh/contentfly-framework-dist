<?php
const ROOT_DIR = __DIR__ . '/../..';

require_once ROOT_DIR.'/lib/contentfly/version.php';
/*
 * ZWEI AUTOLOADER, UND DIE REIHENFOLGE IST DIE ENTSCHEIDUNG.
 *
 * Root zuerst, custom/ ergaenzend. Registrieren beide dasselbe PSR-4-Praefix, bedient es der
 * ZUERST geladene Baum — also immer der Root, unabhaengig davon, welche Version aktueller ist.
 * Das ist ab 006-004-0001 eine Zusicherung, nicht mehr eine Nebenwirkung: Framework schlaegt
 * Projekt. Wer die beiden Bloecke tauscht, kehrt sie um.
 *
 * Begruendung und die verworfene Alternative (ein einziger Autoloader) stehen in
 * an_project/docs/architecture.md unter "Key decisions", 2026-09-09.
 *
 * DIE ZUSICHERUNG HAENGT AN EINER BEDINGUNG: dass sich die beiden Baeume nicht ueberschneiden.
 * Frueher taten sie es — psr/log lag in 1.1.3 und 3.0.2 gleichzeitig im Prozess, dazu zwei
 * symfony/polyfill-* in unvereinbaren Staenden und ein handkopiertes PHPMailer\PHPMailer\, das
 * in keiner installed.json stand. Jahrelang, ohne dass es jemandem auffiel. Geprueft wird die
 * Bedingung deshalb in tests/Unit/AutoloaderUeberschneidungTest.php.
 */
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
use Areanet\PIM\Command\TokenCleanupCommand;
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
use Areanet\PIM\Classes\Kernel\Application;
use Knp\Provider\ConsoleServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

// Die Annotationen des Frameworks sind ueber PSR-4 autoladbar; ein registerFile() dafuer
// waere ueberfluessig — und schaedlich, siehe unten.
//
// Der registerLoader() ist dagegen noetig, und zwar wegen einer Falle in
// AnnotationRegistry::loadAnnotationClass():
//
//     if (self::$loaders === [] && self::$autoloadNamespaces === []
//         && self::$registerFileUsed === false && class_exists($class)) {
//         return true;
//     }
//
// Der moderne Fallback — "nimm einfach den Composer-Autoloader" — greift NUR, solange
// registerFile() nie benutzt wurde. TypeManager tut das aber fuer Plugin-Annotationen
// (plugins/ liegt ausserhalb des Autoloaders und hat keine andere Moeglichkeit). Sobald ein
// Plugin einen eigenen Typ mitbringt, faellt der Fallback weg — und Doctrine findet seine
// EIGENEN Annotationen nicht mehr:
//
//     [Semantical Error] The annotation "@Doctrine\ORM\Mapping\MappedSuperclass" in class
//     Areanet\PIM\Entity\Base was never imported.
//
// Ein ausdruecklicher Loader stellt denselben Effekt her, unabhaengig davon, was spaeter
// noch registriert wird. Gefunden mit 006-002-0003 beim Doctrine-Wechsel; die Falle steckt
// aber in beiden Doctrine-Staenden gleichermassen.
if (method_exists(AnnotationRegistry::class, 'registerLoader')) {
    @AnnotationRegistry::registerLoader('class_exists');
}

/*
 * FEHLERAUSGABE — BIS 000-000-0018 WAR SIE VERKEHRT HERUM VERDRAHTET.
 *
 * Der Block hatte keinen else-Zweig: Im Debug-Modus wurde display_errors eingeschaltet, im
 * Produktionsbetrieb NICHTS gesetzt. Es galt, was die php.ini der Maschine sagte — und das
 * offizielle php:*-Image laedt keine. Dort gilt dann der Compile-Default display_errors=On,
 * und eine Produktionsinstanz liefert Deprecations, Warnings und Dateipfade an jeden
 * Aufrufer aus.
 *
 * Die Einstellung wurde also dort gesetzt, wo sie unkritisch ist, und dort weggelassen, wo
 * sie zaehlt.
 *
 * ── Warum das Framework es erzwingt und nicht dem Deployment ueberlaesst ───────────────
 *
 * Weil der Schaden eintritt, wenn NICHTS konfiguriert ist. Eine Anforderung an die
 * Zielumgebung waere nur so gut wie die Umgebung, die sie liest; hier ist der unsichere
 * Zustand der Standardzustand. Ein Framework, das Datenhaltung und API stellt, darf nicht
 * davon abhaengen, dass jemand daran gedacht hat.
 *
 * log_errors bleibt an: Was nicht ausgeliefert wird, soll trotzdem auffindbar sein — und es
 * ist die Quelle, aus der das "0 Deprecations"-Gate aus 006-005 liest.
 *
 * ── Warum im Debug-Modus jetzt E_ALL steht ────────────────────────────────────────────
 *
 * Vorher: E_ALL ^E_NOTICE ^E_DEPRECATED. Deprecations wurden ausgerechnet dort unterdrueckt,
 * wo ein Entwickler sie sehen will. Das widerspricht der Vorgabe aus
 * an_project/docs/tech-stack.md, deprecation-frei zu bauen: Wer sie sehen soll, sah sie
 * nicht; wer sie nicht sehen soll, bekam sie.
 *
 * ── Was hiermit NICHT behoben ist ─────────────────────────────────────────────────────
 *
 * Die Kopplung selbst: PHP schreibt eine Deprecation direkt in den Antwortstrom, und wenn
 * das geschieht, bevor Silex den Statuscode setzt, sind die Header schon unterwegs — die
 * Antwort traegt dann 200, obwohl die Anwendung 405 oder 500 meint. Mit display_errors=Off
 * kann das im Produktionsbetrieb nicht mehr eintreten, weil nichts mehr in den Strom
 * geschrieben wird. Im Debug-Modus bleibt es moeglich.
 *
 * Ein ob_start() hier wuerde es auch dort loesen und ist bewusst NICHT gesetzt: Die
 * Dateiauslieferung antwortet mit einer StreamedResponse (FileController::getAction()), und
 * ein Ausgabepuffer zoege jede ausgelieferte Datei durch den Speicher. Die strukturelle
 * Loesung kommt mit Epic 009 — Symfonys Fehlerbehandlung wandelt Fehler in Ausnahmen um,
 * statt sie auszugeben.
 */
if(Adapter::getConfig()->APP_DEBUG){
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}else{
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
}

/*
 * Areanet\PIM\Classes\Kernel\Application statt Silex\Application (009-001-0001).
 *
 * Die Klasse erbt heute von Silex und sagt ueber ApplicationInterface zu, was das
 * Framework von ihr benutzt. Mit 009-002 faellt die Vererbung weg und ein
 * Symfony-7.4-Kernel tritt an ihre Stelle; die Schnittstelle bleibt dieselbe, und kein
 * Aufrufer merkt den Wechsel.
 *
 * Diese Datei ist bis dahin eine der wenigen Stellen, die Silex ueberhaupt noch nennen
 * duerfen — der Bootstrap ist die Stelle, die den Kernel kennen soll. Welche Stellen das
 * sind, haelt tests/Unit/Kernel/KeineSilexTypenTest.php fest (009-001-0005).
 */
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
        $app->add(new TokenCleanupCommand());
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
