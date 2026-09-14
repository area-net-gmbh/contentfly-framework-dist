<?php
namespace Areanet\PIM\Classes\ORM;

use Doctrine\DBAL\Connection;
use Areanet\PIM\Classes\Events\LoadMetadata;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;

/**
 * Builds the `EntityManager` — as a replacement for `dflydev/doctrine-orm-service-provider`.
 *
 * ## Why this class exists
 * `dflydev/doctrine-orm-service-provider` v2.0.1 is the **last** version (2018) and uses
 * `Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain`. `doctrine/persistence` 2.0
 * moved this namespace to `Doctrine\Persistence\` — and `doctrine/orm` from 2.14 on requires
 * `persistence ^2.4 || ^3`. The provider and a PHP-8-capable ORM are therefore mutually
 * exclusive (`006-002-0005`).
 *
 * Composer does not see this: `dflydev` declares no constraints on `doctrine/persistence`,
 * so the lock resolves cleanly and the application only breaks at the first `$app['orm.em']`.
 *
 * ## This class is a stopgap
 * **Epic `009` throws it away**, together with Silex and Pimple: a Symfony kernel builds the
 * EntityManager via the DoctrineBundle. It is deliberately kept lean and only covers what the
 * framework really uses today — not what the provider was able to do:
 *
 * | dflydev | here |
 * |---|---|
 * | any number of connections | **one** |
 * | six cache drivers | none — `bootstrap.php` sets them itself afterwards |
 * | five mapping formats | only `annotation` |
 * | 466 lines | this |
 *
 * Anyone who wants to add something here that Epic `009` clears away anyway should refrain.
 *
 * ## What must **not** change
 * The container key remains `$app['orm.em']`; 26 files access it, and the test suite checks
 * the behaviour behind it. `$app['orm.ems']` and `$app['orm.em.config']` were also part of the
 * provider's surface, but are used nowhere (checked across `lib/`, `custom/` and `tests/`) —
 * they have therefore not been rebuilt.
 */
final class EntityManagerFactory
{
    /**
     * Creates the EntityManager for the given connection.
     *
     * @param Connection            $connection   the DBAL connection, today `$app['dbs']['pim']`
     * @param array<int,array{namespace:string,path:string}> $mappings namespace → directory
     * @param string                $proxyDir     directory for the generated proxies
     * @param bool                  $autoGenerateProxies
     * @param array<string,string>  $numericFunctions  custom DQL functions
     * @param CacheItemPoolInterface|null $queryCache  PSR-6 pool, or null for no cache
     * @param CacheItemPoolInterface|null $metadataCache ditto
     */
    public static function create(
        Connection $connection,
        array $mappings,
        string $proxyDir,
        bool $autoGenerateProxies,
        array $numericFunctions = array(),
        ?CacheItemPoolInterface $queryCache = null,
        ?CacheItemPoolInterface $metadataCache = null
    ): EntityManager {
        $config = new Configuration();

        // A chain instead of one driver with two paths: this keeps the mapping
        // namespace → directory explicit, instead of letting Doctrine guess it from the file
        // names. That is exactly what dflydev did as well.
        //
        // `Doctrine\Persistence\…\MappingDriverChain` exists in persistence 1.3 AND 2/3 —
        // which is why this class runs against the old tree as well as the new one. The old
        // namespace `Doctrine\Common\Persistence\…` no longer exists in 2.0; that is exactly
        // what dflydev failed on.
        $chain = new MappingDriverChain();

        foreach ($mappings as $mapping) {
            // ATTRIBUTES INSTEAD OF ANNOTATIONS, FOR ALL NAMESPACES (010-001-0003).
            //
            // This used to be `$config->newDefaultAnnotationDriver($path, false)`.
            //
            // IT ONLY WORKS ALL TOGETHER, and that is measured: Doctrine reads a namespace with
            // exactly ONE driver, so a cut per namespace seemed possible —
            // `Areanet\PIM\Entity` first, `Custom\Entity` afterwards. It does not hold.
            // `Custom\Entity\Core\Example` inherits from `Areanet\PIM\Entity\Base`, and for a
            // MappedSuperclass Doctrine sets NO `inherited` on the inherited fields
            // (`ClassMetadataFactory::addMappingInheritanceInformation()`). The subclass's driver
            // therefore reads them AFRESH — an annotation driver finds nothing on a converted
            // superclass any more and reports
            //
            //     No identifier/primary key specified for Entity "Custom\Entity\Core\Example"
            //     sub class of "Areanet\PIM\Entity\Base".
            //
            // A per-mapping selector switch therefore stood here briefly and has been removed
            // again: it would have offered a freedom that does not exist.
            $chain->addDriver(new AttributeDriver(array($mapping['path'])), $mapping['namespace']);
        }

        $config->setMetadataDriverImpl($chain);

        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('DoctrineProxy');
        $config->setAutoGenerateProxyClasses($autoGenerateProxies);

        foreach ($numericFunctions as $name => $class) {
            $config->addCustomNumericFunction($name, $class);
        }

        /*
         * THE CACHES BELONG HERE, NOT IN THE BOOTSTRAP (010-002-0005).
         *
         * Until now bootstrap.php set them on the configuration AFTER this method had built
         * the EntityManager. For the query cache that worked fine: Doctrine reads
         * `getQueryCache()` on every query. The metadata cache, by contrast, is read EXACTLY
         * ONCE — in `EntityManager::__construct()`, via `configureMetadataCache()`. Whatever
         * comes afterwards is never seen by the ClassMetadataFactory.
         *
         * Measured, without a database: cache set before the EntityManager -> 2 cache files
         * after a metadata query; set afterwards -> 0. And in the test run against the real
         * server, `data/cache/metadata` stayed empty, with doctrine/cache just as with PSR-6 —
         * so the metadata cache never took effect.
         *
         * `null` means: no cache. The caller decides that, not this method — in debug mode and
         * on the console none should be active, otherwise a developer works against stale
         * metadata.
         */
        if ($queryCache !== null) {
            $config->setQueryCache($queryCache);
        }

        if ($metadataCache !== null) {
            $config->setMetadataCache($metadataCache);
        }

        // `new EntityManager(...)` INSTEAD OF `EntityManager::create(...)` (010-003-0001).
        //
        // The static factory is removed in ORM 3; in 2.20 it is deprecated and the
        // constructor is already public. That is why the change is made HERE, before the
        // version jump: it can be measured against an unchanged ORM.
        //
        // It was the first of two blockers of the jump — the whole tree died at this line,
        // 196 of 268 tests (measurement in the story text).
        $em = new EntityManager($connection, $config);

        /*
         * THE modified_index LISTENER BELONGS HERE (010-005-0002).
         *
         * It attaches an index on `modified` to every entity. It used to be registered in
         * bootstrap.php — INSIDE `if($app['is_installed'])`. But `appcms:install` runs
         * precisely when is_installed is FALSE: the listener never took effect there, the index
         * was never created, and ever since, `orm:validate-schema` reported on EVERY
         * installation that schema and mapping are not in sync.
         *
         * THIS IS THE THIRD EDITION OF THE SAME BUG IN EPIC 010, and that is why the
         * registration now lives here instead of with the caller:
         *
         *   010-002-0005   The metadata cache was set after the EntityManager and
         *                  never reached the ClassMetadataFactory.
         *   010-003-0002   The installer repeated the mapping block from bootstrap.php,
         *                  and the repetition diverged.
         *   010-005-0002   This listener.
         *
         * The rule behind it: whatever must apply to EVERY EntityManager belongs in the factory.
         * If it sits with the caller, it has to be there several times — and then it is missing
         * somewhere.
         */
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new LoadMetadata());

        return $em;
    }
}
