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
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\MemcachedCache;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Events;
use Areanet\PIM\Classes\Kernel\ConsoleEvents;
use Areanet\PIM\Classes\Kernel\Application;
use Areanet\PIM\Classes\Kernel\Console;
use Areanet\PIM\Classes\Kernel\ConsoleInitEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

// DIE AnnotationRegistry IST WEG (010-001-0005).
//
// Hier stand ein `AnnotationRegistry::registerLoader('class_exists')`. Er war noetig wegen
// einer Falle in `loadAnnotationClass()`: Der moderne Fallback — "nimm einfach den
// Composer-Autoloader" — griff nur, solange `registerFile()` nie benutzt wurde, und der
// TypeManager tat genau das. Sobald ein Plugin einen eigenen Typ mitbrachte, fand Doctrine
// seine eigenen Annotationen nicht mehr. Gefunden mit `006-002-0003`.
//
// Mit den Attributen aus `010-001` ist die ganze Mechanik gegenstandslos: Ein Attribut nennt
// eine echte Klasse, die der Autoloader laedt. `doctrine/annotations` ist aus dem Manifest.

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
 * das geschieht, bevor der Kernel den Statuscode setzt, sind die Header schon unterwegs — die
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
 * DER WECHSEL IST VOLLZOGEN (009-002). Die Klasse erbte zunaechst noch von Silex und sagte
 * ueber ApplicationInterface zu, was das Framework von ihr benutzt; genau diese Zusicherung
 * hat den Tausch des Unterbaus getragen. Heute erbt sie von Kernel\Container und setzt einen
 * Symfony-7.4-HttpKernel zusammen. Die Schnittstelle ist dieselbe geblieben, und kein
 * Aufrufer hat den Wechsel gemerkt.
 *
 * Dass Silex, Pimple und knplabs nirgends mehr im Baum vorkommen, haelt
 * tests/Unit/Kernel/KeineSilexTypenTest.php fest — mit leerer Ausnahmeliste, seit 009-002.
 */
$app = new Application();

$app['is_installed'] = (Adapter::getConfig()->DB_HOST != '$SET_DB_HOST');
$app['auth.user'] = null;

Adapter::setHostname(HOST);
date_default_timezone_set(Adapter::getConfig()->APP_TIMEZONE);

/*
 * Der ServiceControllerServiceProvider ist mit Silex entfallen (009-002-0002).
 *
 * Er erlaubte, einen Controller als "dienst:methode" zu benennen — also als Container-Schluessel
 * plus Methodenname statt als Klasse. Genau davon lebt der RouteManager. Die Faehigkeit bleibt,
 * sie liegt jetzt im Controller-Resolver der Anwendung; gebaut wird sie in 009-002-0003.
 */


if(Adapter::getConfig()->APP_LANGUAGES){
    define('APP_CMS_MAIN_LANG', Adapter::getConfig()->APP_LANGUAGES[0]);
}else{
    define('APP_CMS_MAIN_LANG', null);
}

if($app['is_installed']) {
    if (Adapter::getConfig()->DB_GUID_STRATEGY) {
        define('APPCMS_ID_TYPE', 'string');
        define('APPCMS_ID_STRATEGY', 'CUSTOM');
    } else {
        define('APPCMS_ID_TYPE', Adapter::getConfig()->DB_ID_INTEGER_TYPE);
        define('APPCMS_ID_STRATEGY', 'AUTO');
    }

    /*
     * Die Datenbankverbindung, selbst gebaut (009-002-0002).
     *
     * Hier stand `$app->register(new Silex\Provider\DoctrineServiceProvider(), …)`. Der
     * Provider legte `$app['dbs']` als Sammlung benannter Verbindungen an und `$app['db']` als
     * Verweis auf die erste. Beide Schluessel werden im Baum gelesen — `bin/console.php` und
     * `EntityManagerFactory` — und bleiben deshalb genau so bestehen.
     *
     * Gebaut wird die Verbindung mit `DriverManager`, wie es `$app['database']` weiter unten
     * seit jeher tut. Der Unterschied zwischen den beiden: `$app['db']` ist die Verbindung, die
     * der EntityManager benutzt, `$app['database']` eine zweite fuer direktes SQL. Dass es zwei
     * sind, ist aelter als dieser Task und wird hier nicht angefasst.
     */
    $app['dbs.options'] = array(
        'pim' => array(
            'driver'   => 'pdo_mysql',
            'host'     => Adapter::getConfig()->DB_HOST,
            // Ohne den Port landet die Verbindung immer auf 3306 - und zwar still,
            // also auf irgendeiner MySQL, die dort zufaellig lauscht (Task 000-000-0004).
            'port'     => Adapter::getConfig()->DB_PORT,
            'dbname'   => Adapter::getConfig()->DB_NAME,
            'user'     => Adapter::getConfig()->DB_USER,
            'password' => Adapter::getConfig()->DB_PASS,
            'charset'  => Adapter::getConfig()->DB_CHARSET,
            'defaultTableOptions' => array(
                'charset' => Adapter::getConfig()->DB_CHARSET,
                'collate' => Adapter::getConfig()->DB_COLLATE
            )
        )
    );

    $app['dbs'] = function ($app) {
        $verbindungen = array();

        foreach ($app['dbs.options'] as $name => $optionen) {
            $verbindungen[$name] = DriverManager::getConnection($optionen);
        }

        return $verbindungen;
    };

    // Die erste benannte Verbindung, wie sie der Provider ausgewiesen hat.
    $app['db'] = function ($app) {
        $verbindungen = $app['dbs'];

        return reset($verbindungen);
    };
}

/*
 * Die Console, selbst gebaut (009-002-0005).
 *
 * Hier stand `$app->register(new ConsoleServiceProvider(), …)`. Das Paket deckelte
 * symfony/console auf ^4 und ist mit 009-002-0001 weg; was es lieferte, waren drei Dinge — eine
 * Console mit Namen und Version, ein Zugriff auf die Anwendung und das Ereignis console.init.
 * Alle drei stehen jetzt in Areanet\PIM\Classes\Kernel\Console.
 *
 * Als faule Factory, wie vorher: bin/console.php holt sie ab, der Web-Einstieg nie.
 */
$app['console'] = function ($app) {
    return new Console($app, 'PIM', APP_VERSION, ROOT_DIR);
};

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
            /*
             * PSR-6 STATT doctrine/cache (010-002-0001).
             *
             * ORM 2.20 nimmt beides entgegen, ORM 3 nur noch PSR-6 — also laesst sich der
             * Wechsel hier gegen ein unveraendertes ORM messen. Die Adapter kommen aus
             * symfony/cache; der Stack steht ohnehin auf Symfony 7.4.
             *
             * DIE TRENNUNG DER NAMENSRAEUME IST DER EMPFINDLICHE TEIL. Sie war jahrelang
             * beabsichtigt und griff nicht: Bis 009-003-0002 stand hier `new ApcCache('query')`,
             * und die Klasse hat gar keinen Konstruktor — das Argument wurde stillschweigend
             * verworfen, beide Caches lagen im selben Namensraum. Bei Symfonys Adaptern ist
             * der Namensraum ein Konstruktor-Argument, das nicht ins Leere laufen kann.
             *
             * `apc` und `memcached` stehen noch auf doctrine/cache; sie entscheidet
             * 010-002-0002.
             */
            case 'apc':
                $config->setQueryCacheImpl($cacheImpl = new ApcCache());
                $cacheImpl->setNamespace('query');
                $config->setMetadataCacheImpl($cacheImpl = new ApcCache());
                $cacheImpl->setNamespace('metadata');
                break;
            case 'apcu':
                $config->setQueryCache(new ApcuAdapter('query'));
                $config->setMetadataCache(new ApcuAdapter('metadata'));
                break;
            case 'memcached':
                $cache = new MemcachedCache();
                $cache->setMemcached(new Memcached());

                $config->setQueryCacheImpl($cache);
                $config->setMetadataCacheImpl($cache);
                break;
            case 'filesystem':
            default:
                // Namensraum leer, Verzeichnis ausdruecklich: Die Trennung liegt hier in den
                // Pfaden, wie bisher. Ein zusaetzlicher Namensraum wuerde nur eine weitere
                // Ebene darunter anlegen.
                $config->setQueryCache(new FilesystemAdapter('', 0, ROOT_DIR . '/data/cache/query'));
                $config->setMetadataCache(new FilesystemAdapter('', 0, ROOT_DIR . '/data/cache/metadata'));
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
    // console() statt getApplication() seit 009-002-0005: Das Ereignis liefert die Console,
    // und "Application" waere in diesem Baum doppeldeutig — es gibt auch die Anwendung.
    $dispatcher->addListener(ConsoleEvents::INIT, function (ConsoleInitEvent $event) {
        // addCommand() statt add(): Letzteres ist seit Symfony 7.4 deprecated (009-003-0002).
        $console = $event->console();
        $console->addCommand(new InstallCommand());
        $console->addCommand(new SetupCommand());
        $console->addCommand(new TokenCleanupCommand());
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

/*
 * Der ValidatorServiceProvider ist mit symfony/validator entfallen (009-002-0001). Er wurde
 * registriert, und `$app['validator']` hat ihn nie jemand abgeholt — im ganzen Baum keine
 * Fundstelle, keine @Assert-Annotation, kein anderes Paket, das ihn anfordert.
 */

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
