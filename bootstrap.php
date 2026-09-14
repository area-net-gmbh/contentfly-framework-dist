<?php
/*
 * THE PROJECT DIRECTORY COMES FROM THE ENTRY POINT (007-001-0002).
 *
 * This used to say:
 *
 *     const ROOT_DIR = __DIR__ . '/../..';
 *
 * The framework computed the project directory from ITS OWN LOCATION — correct as long as it lives
 * under `lib/contentfly/` inside the project, wrong the moment it lives as a package under
 * `vendor/`. And wrong in the silent way: the computed path does not exist, but it IS a path, and
 * the follow-up message is about a missing file instead of a wrong root.
 *
 * Since 007-001-0003 no entry point includes this file directly. It is reached through
 * `Classes\Kernel\Start`, which checks the preconditions and has set the project directory. If it
 * is not set, startup ends here — with a message about exactly that.
 */
if (!\Areanet\PIM\Classes\Kernel\Paths::isSet()) {
    throw new \RuntimeException(
        "Contentfly cannot start.\n\n"
        ."lib/contentfly/bootstrap.php is no longer an entry point (007-001-0003). The\n"
        ."entry point loads the autoloader and then calls:\n\n"
        ."    \\Areanet\\PIM\\Classes\\Kernel\\Start::web(\$projectDir);\n"
        ."    \\Areanet\\PIM\\Classes\\Kernel\\Start::console(\$projectDir);\n\n"
        ."Start checks the preconditions and includes this file."
    );
}

/*
 * Fully qualified and put into two local values: the `use` block of this file sits further down,
 * behind the first `require`s — so up here it cannot be read yet without every reader (and
 * PHPStan) having to look it up first.
 */
$packageDir = \Areanet\PIM\Classes\Kernel\Paths::package();
$customDir  = \Areanet\PIM\Classes\Kernel\Paths::custom();

require_once $packageDir.'/version.php';
/*
 * THERE USED TO BE TWO AUTOLOADERS HERE (until 007-001-0003).
 *
 * The root tree first, `custom/vendor/` as a supplement — with the guarantee from `006-004-0001`
 * that the root wins on a shared PSR-4 prefix. It was needed as long as framework and project were
 * two separate Composer trees in the same process.
 *
 * With the library package its basis disappears: the framework is a dependency INSIDE the
 * project's tree. There are no longer two trees whose precedence would need settling — and
 * Composer refuses incompatible constraints while resolving instead of loading two versions side by
 * side into the process. The case this failed on for years (psr/log in 1.1.3 and 3.0.2 at the same
 * time) can no longer occur.
 *
 * The autoloader itself is no longer loaded here but by the entry point — see
 * `Classes\Kernel\Start`. Start rejects a leftover `custom/vendor/` instead of silently ignoring
 * it.
 *
 * Decision and rejected alternatives: an_project/docs/architecture.md, Key decisions, 2026-09-11.
 * `tests/Unit/AutoloaderUeberschneidungTest.php` has been turned around and now checks that there
 * stays one tree.
 */
require_once $customDir.'/config.php';
require_once $customDir.'/version.php';

define('HOST', $_SERVER["SERVER_NAME"] ?? 'default');

use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Auth;
use Areanet\PIM\Classes\Mailer;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Kernel\Paths;
use Areanet\PIM\Classes\Helper;
use Areanet\PIM\Classes\Manager\ConsoleManager;
use Areanet\PIM\Classes\Manager\PluginManager;
use Areanet\PIM\Classes\Manager\RouteManager;
use Areanet\PIM\Classes\Manager\TypeManager;
use Areanet\PIM\Classes\ORM\Mapping\ContentflyQuoteStrategy;
use Areanet\PIM\Command\InstallCommand;
use Areanet\PIM\Command\SetupCommand;
use Areanet\PIM\Command\ProviderAbgleichCommand;
use Areanet\PIM\Command\ReencryptCommand;
use Areanet\PIM\Command\TokenCleanupCommand;
use Areanet\PIM\Classes\ORM\EntityManagerFactory;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Areanet\PIM\Classes\Security\Anbieterverzeichnis;
use Areanet\PIM\Classes\Security\Anmeldebremse;
use Areanet\PIM\Classes\Security\Benutzerbereitstellung;
use Areanet\PIM\Classes\Security\Gruppenabbildung;
use Areanet\PIM\Classes\Security\TokenAuthenticator;
use Areanet\PIM\Classes\Security\UserLoader;
use Areanet\PIM\Classes\Security\TokenHandler;
use Areanet\PIM\Classes\Security\TokenSources;
use Doctrine\DBAL\DriverManager;
use Areanet\PIM\Classes\Kernel\ConsoleEvents;
use Areanet\PIM\Classes\Kernel\Application;
use Areanet\PIM\Classes\Kernel\Console;
use Areanet\PIM\Classes\Kernel\ConsoleInitEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

// THE AnnotationRegistry IS GONE (010-001-0005).
//
// This used to hold an `AnnotationRegistry::registerLoader('class_exists')`. It was needed because
// of a trap in `loadAnnotationClass()`: the modern fallback — "just use the Composer autoloader" —
// only applied as long as `registerFile()` had never been used, and the TypeManager did exactly
// that. As soon as a plugin brought its own type, Doctrine no longer found its own annotations.
// Found with `006-002-0003`.
//
// With the attributes from `010-001` the whole mechanism is moot: an attribute names a real class
// that the autoloader loads. `doctrine/annotations` is out of the manifest.

/*
 * ERROR OUTPUT — UNTIL 000-000-0018 IT WAS WIRED THE WRONG WAY ROUND.
 *
 * The block had no else branch: in debug mode display_errors was switched on, in production
 * NOTHING was set. Whatever the machine's php.ini said applied — and the official php:* image
 * loads none. There the compile-time default display_errors=On applies, and a production instance
 * delivers deprecations, warnings and file paths to every caller.
 *
 * So the setting was made where it is harmless and left out where it matters.
 *
 * ── Why the framework enforces it instead of leaving it to the deployment ────────────────
 *
 * Because the damage happens when NOTHING is configured. A requirement on the target environment
 * would only be as good as the environment reading it; here the insecure state is the default
 * state. A framework that provides data storage and an API must not depend on someone having
 * thought of it.
 *
 * log_errors stays on: whatever is not delivered should still be findable — and it is the source
 * the "0 deprecations" gate from 006-005 reads.
 *
 * ── Why debug mode now uses E_ALL ─────────────────────────────────────────────────────
 *
 * Before: E_ALL ^E_NOTICE ^E_DEPRECATED. Deprecations were suppressed exactly where a developer
 * wants to see them. That contradicts the requirement in an_project/docs/tech-stack.md to build
 * deprecation-free: whoever should see them did not; whoever should not got them.
 *
 * ── What this does NOT fix ────────────────────────────────────────────────────────────
 *
 * The coupling itself: PHP writes a deprecation directly into the response stream, and if that
 * happens before the kernel sets the status code, the headers are already on their way — the
 * response then carries 200 although the application means 405 or 500. With display_errors=Off
 * this can no longer happen in production, because nothing is written into the stream any more.
 * In debug mode it remains possible.
 *
 * An ob_start() here would solve it there as well and is deliberately NOT set: file delivery
 * answers with a StreamedResponse (FileController::getAction()), and an output buffer would pull
 * every delivered file through memory. The structural solution comes with Epic 009 — Symfony's
 * error handling turns errors into exceptions instead of printing them.
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
 * Areanet\PIM\Classes\Kernel\Application instead of Silex\Application (009-001-0001).
 *
 * THE SWITCH IS COMPLETE (009-002). The class first still extended Silex and, through
 * ApplicationInterface, guaranteed what the framework uses of it; exactly that guarantee carried
 * the replacement of the foundation. Today it extends Kernel\Container and assembles a Symfony 7.4
 * HttpKernel. The interface has stayed the same, and no caller noticed the switch.
 *
 * tests/Unit/Kernel/KeineSilexTypenTest.php records that Silex, Pimple and knplabs no longer occur
 * anywhere in the tree — with an empty exception list since 009-002.
 */
$app = new Application();

$app['is_installed'] = (Adapter::getConfig()->DB_HOST != '$SET_DB_HOST');
$app['auth.user'] = null;

Adapter::setHostname(HOST);
date_default_timezone_set(Adapter::getConfig()->APP_TIMEZONE);

/*
 * The ServiceControllerServiceProvider went away with Silex (009-002-0002).
 *
 * It allowed naming a controller as "service:method" — a container key plus a method name instead
 * of a class. The RouteManager lives on exactly that. The capability remains; it now lives in the
 * application's controller resolver and is built in 009-002-0003.
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
     * The database connection, built by the framework itself (009-002-0002).
     *
     * This used to say `$app->register(new Silex\Provider\DoctrineServiceProvider(), …)`. The
     * provider created `$app['dbs']` as a collection of named connections and `$app['db']` as a
     * reference to the first one. Both keys are read in the tree — `bin/console.php` and
     * `EntityManagerFactory` — and therefore stay exactly as they are.
     *
     * The connection is built with `DriverManager`, as `$app['database']` further down has always
     * done. The difference between the two: `$app['db']` is the connection the EntityManager uses,
     * `$app['database']` a second one for direct SQL. That there are two is older than this task
     * and is not touched here.
     */
    $app['dbs.options'] = array(
        'pim' => array(
            'driver'   => 'pdo_mysql',
            'host'     => Adapter::getConfig()->DB_HOST,
            // Without the port the connection always ends up on 3306 - silently, that is, on
            // whatever MySQL happens to listen there (task 000-000-0004).
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
        $connections = array();

        foreach ($app['dbs.options'] as $name => $options) {
            $connections[$name] = DriverManager::getConnection($options);
        }

        return $connections;
    };

    // The first named connection, as the provider exposed it.
    $app['db'] = function ($app) {
        $connections = $app['dbs'];

        return reset($connections);
    };
}

/*
 * The console, built by the framework itself (009-002-0005).
 *
 * This used to say `$app->register(new ConsoleServiceProvider(), …)`. The package capped
 * symfony/console at ^4 and is gone since 009-002-0001; what it provided was three things — a
 * console with name and version, access to the application and the console.init event. All three
 * now live in Areanet\PIM\Classes\Kernel\Console.
 *
 * As a lazy factory, as before: bin/console.php fetches it, the web entry never does.
 */
$app['console'] = function ($app) {
    return new Console($app, 'PIM', APP_VERSION, Paths::project());
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

/**
 * Builds ONE cache pool according to `APP_CACHE_DRIVER`.
 *
 * Extracted with 013-001-0003: the driver is now needed in three places — the two Doctrine caches
 * and the storage of the login throttle. The selection therefore lives here once instead of three
 * times side by side.
 *
 * The namespace separates the pool contents; the directory does the same for `filesystem`.
 */
$buildCachePool = static function (string $namespace, string $directory): \Psr\Cache\CacheItemPoolInterface {
    static $memcached = null;

    switch (Adapter::getConfig()->APP_CACHE_DRIVER) {
        case 'apc':
            // REMOVED WITH 010-002-0002 — and explicitly rejected, not silently falling back
            // to the default.
            //
            // The branch used `Doctrine\Common\Cache\ApcCache`, which calls `apc_fetch()`.
            // The APC extension no longer exists for PHP 7 and 8; measured,
            // `function_exists('apc_fetch')` is false. It could not run on any supported
            // version — a trap, not a setting.
            //
            // A silent fallback to `filesystem` would be more convenient and wrong: the
            // operator would keep believing their cache lives in shared memory.
            throw new \RuntimeException(
                'APP_CACHE_DRIVER = "apc" no longer exists. The APC extension was dropped with '
                .'PHP 7; its successor is called "apcu". See '
                .'an_project/docs/breaking-changes.md.'
            );
        case 'apcu':
            // The check is the difference between a message and a fatal error on first
            // access.
            if (!ApcuAdapter::isSupported()) {
                throw new \RuntimeException(
                    'APP_CACHE_DRIVER = "apcu" requires the apcu extension; it is not loaded in '
                    .'this PHP.'
                );
            }

            return new ApcuAdapter($namespace);
        case 'memcached':
            if (!MemcachedAdapter::isSupported()) {
                throw new \RuntimeException(
                    'APP_CACHE_DRIVER = "memcached" requires the memcached extension; it is '
                    .'not loaded in this PHP.'
                );
            }

            // A SERVER NOW COMES FROM THE CONFIGURATION (010-002-0002).
            //
            // Before: `new Memcached()` without a single `addServer()`. Such a client stores
            // nothing — the branch was ineffective even with the extension present, and
            // silently so.
            //
            // And it shared ONE instance for both caches, while the other branches separate
            // them. Here the namespaces now separate them, as with apcu. The connection itself
            // is shared — it is the channel, not the content.
            $memcached ??= MemcachedAdapter::createConnection(
                Adapter::getConfig()->APP_CACHE_MEMCACHED_DSN
            );

            return new MemcachedAdapter($memcached, $namespace);
        case 'filesystem':
        default:
            // Empty namespace, explicit directory: separation lives in the paths here, as
            // before. An additional namespace would only create another level below.
            return new FilesystemAdapter('', 0, $directory);
    }
};

/**
 * The login throttle (013-001-0003).
 *
 * IT ALWAYS GETS A STORAGE — unlike the Doctrine caches, which are switched off in debug mode and
 * on the console. A throttle against password guessing that switches itself off with `APP_DEBUG`
 * would be no throttle: debug mode is a convenience for the developer, not a reason to leave the
 * application open. And a counter that does not survive across requests counts nothing.
 */
/**
 * The allowlist of login providers (013-004-0001).
 *
 * EMPTY, AND THAT IS THE DEFAULT STATE. A project registers its providers in `custom/app.php`; the
 * framework ships none. As long as nothing is registered, there is no way around the password
 * check — logging in through an external system is a decision someone has to make, not one that
 * is inherited.
 *
 * It lives outside `is_installed`: a project registers its providers before anything is checked,
 * and a registration that depended on the installation would be a trap.
 */
$app['anmeldeanbieter'] = function () {
    return new Anbieterverzeichnis();
};

$app['loginbremse'] = function () use ($buildCachePool) {
    return new Anmeldebremse($buildCachePool('loginbremse', Paths::data() . '/cache/loginbremse'));
};

if($app['is_installed']) {
    // Replaces dflydev/doctrine-orm-service-provider (006-002-0005). The provider has been
    // unchanged since 2018 and uses a namespace that doctrine/persistence 2.0 moved — it thereby
    // blocked every PHP-8-capable ORM. Interim solution until Epic 009.
    /**
     * Selects the two Doctrine caches — or none.
     *
     * They are PASSED to the factory instead of being set on the configuration afterwards: the
     * metadata cache is read exactly once in `EntityManager::__construct()`; the
     * ClassMetadataFactory never sees anything set after that (010-002-0005).
     *
     * No cache in debug mode and not on the console: a developer should not work against stale
     * metadata there. This condition used to sit further down and is unchanged.
     *
     * @return array{0: ?\Psr\Cache\CacheItemPoolInterface, 1: ?\Psr\Cache\CacheItemPoolInterface}
     */
    $selectCaches = static function () use ($buildCachePool): array {
        if (Adapter::getConfig()->APP_DEBUG || defined('APPCMS_CONSOLE')) {
            return array(null, null);
        }

        return array(
            $buildCachePool('query',    Paths::data() . '/cache/query'),
            $buildCachePool('metadata', Paths::data() . '/cache/metadata')
        );
    };

    $app['orm.em'] = function ($app) use ($selectCaches) {
        [$queryCache, $metadataCache] = $selectCaches();

        return EntityManagerFactory::erzeugen(
            $app['dbs']['pim'],
            array(
                array('namespace' => 'Areanet\PIM\Entity', 'path' => Paths::frameworkEntities()),
                array('namespace' => 'Custom\Entity',       'path' => Paths::projectEntities()),
            ),
            Paths::data() . '/cache/doctrine',
            (bool) Adapter::getConfig()->APP_AUTOGENERATE_PROXIES,
            array('Find_In_Set' => '\Areanet\PIM\Classes\ORM\Query\Mysql\FindInSet'),
            $queryCache,
            $metadataCache
        );
    };

    $config = $app['orm.em']->getConfiguration();
    $config->setQuoteStrategy(new ContentflyQuoteStrategy());

    /*
     * AUTHENTICATION (013-002-0004).
     *
     * This is where the switch is flipped: `BaseControllerProvider::checkToken()` is gone, and
     * Symfony's `access_token` authenticator takes its place, driven by the `TokenAuthenticator`.
     *
     * THE HANDLER HAS ITS OWN KEY, not anonymous inside the driver: after authentication the
     * caller needs `lastToken()` for `$app['auth.token']`. The container remembers both
     * results, so there is exactly one instance per request — which the state kept in the handler
     * relies on.
     */
    // Creates users that an external system has recognised (013-004-0002).
    $app['benutzerbereitstellung'] = function ($app) {
        return new Benutzerbereitstellung($app['orm.em']);
    };

    // Maps the groups an external system reports (013-004-0003).
    $app['gruppenabbildung'] = function ($app) {
        return new Gruppenabbildung($app['orm.em']);
    };

    $app['tokenHandler'] = function ($app) {
        return new TokenHandler($app['orm.em']);
    };

    $app['tokenAuthenticator'] = function ($app) {
        return new TokenAuthenticator(
            $app['tokenHandler'],
            TokenSources::chain(),
            new UserLoader($app['orm.em'])
        );
    };

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
    // console() instead of getApplication() since 009-002-0005: the event returns the console,
    // and "Application" would be ambiguous in this tree — there is also the application.
    $dispatcher->addListener(ConsoleEvents::INIT, function (ConsoleInitEvent $event) {
        // addCommand() instead of add(): the latter is deprecated since Symfony 7.4 (009-003-0002).
        $console = $event->console();
        $console->addCommand(new InstallCommand());
        $console->addCommand(new SetupCommand());
        $console->addCommand(new TokenCleanupCommand());
        $console->addCommand(new ReencryptCommand());
        $console->addCommand(new ProviderAbgleichCommand());
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

/*
 * THE LoadMetadata LISTENER USED TO BE HERE (until 010-005-0002).
 *
 * And did not apply during installation: the block lived inside `if($app['is_installed'])`, but
 * `appcms:install` runs exactly when that is false. The `modified_index` index was therefore never
 * created, and `orm:validate-schema` reported on every installation that schema and mapping did
 * not match.
 *
 * It is now registered in `EntityManagerFactory::erzeugen()` — where every EntityManager passes,
 * the installer's included.
 */

/*
 * The ValidatorServiceProvider went away with symfony/validator (009-002-0001). It was registered,
 * and nobody ever fetched `$app['validator']` — no occurrence in the whole tree, no @Assert
 * annotation, no other package requesting it.
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

require_once Paths::custom().'/app.php';

$app['routeManager']->bindRoutes();
