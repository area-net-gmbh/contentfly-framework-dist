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
 * Baut den `EntityManager` — als Ersatz für `dflydev/doctrine-orm-service-provider`.
 *
 * ## Warum es diese Klasse gibt
 * `dflydev/doctrine-orm-service-provider` v2.0.1 ist die **letzte** Version (2018) und benutzt
 * `Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain`. Diesen Namensraum hat
 * `doctrine/persistence` 2.0 nach `Doctrine\Persistence\` verschoben — und `doctrine/orm` ab
 * 2.14 verlangt `persistence ^2.4 || ^3`. Der Provider und ein PHP-8-taugliches ORM schliessen
 * sich damit aus (`006-002-0005`).
 *
 * Composer sieht das nicht: `dflydev` deklariert keine Constraints auf `doctrine/persistence`,
 * also löst der Lock sauber auf und die Anwendung bricht erst beim ersten `$app['orm.em']`.
 *
 * ## Diese Klasse ist eine Übergangslösung
 * **Epic `009` wirft sie weg**, zusammen mit Silex und Pimple: Ein Symfony-Kernel baut den
 * EntityManager über das DoctrineBundle. Sie ist bewusst schlank gehalten und bildet nur ab,
 * was das Framework heute wirklich benutzt — nicht, was der Provider konnte:
 *
 * | dflydev | hier |
 * |---|---|
 * | beliebig viele Verbindungen | **eine** |
 * | sechs Cache-Treiber | keiner — `bootstrap.php` setzt sie danach selbst |
 * | fünf Mapping-Formate | nur `annotation` |
 * | 466 Zeilen | dies |
 *
 * Wer hier etwas ergänzen möchte, das Epic `009` ohnehin abräumt, sollte es lassen.
 *
 * ## Was sich **nicht** ändern darf
 * Der Container-Schlüssel bleibt `$app['orm.em']`; 26 Dateien greifen darauf zu, und die
 * Testsuite prüft das Verhalten dahinter. `$app['orm.ems']` und `$app['orm.em.config']` waren
 * ebenfalls Teil der Oberfläche des Providers, werden aber nirgends benutzt (geprüft über
 * `lib/`, `custom/` und `tests/`) — sie sind deshalb nicht nachgebaut.
 */
final class EntityManagerFactory
{
    /**
     * Erzeugt den EntityManager für die übergebene Verbindung.
     *
     * @param Connection            $connection   die DBAL-Verbindung, heute `$app['dbs']['pim']`
     * @param array<int,array{namespace:string,path:string}> $mappings Namensraum → Verzeichnis
     * @param string                $proxyDir     Verzeichnis für die generierten Proxies
     * @param bool                  $autoGenerateProxies
     * @param array<string,string>  $numericFunctions  eigene DQL-Funktionen
     * @param CacheItemPoolInterface|null $abfrageCache  PSR-6-Pool oder null fuer keinen Cache
     * @param CacheItemPoolInterface|null $metadatenCache dito
     */
    public static function erzeugen(
        Connection $connection,
        array $mappings,
        string $proxyDir,
        bool $autoGenerateProxies,
        array $numericFunctions = array(),
        ?CacheItemPoolInterface $abfrageCache = null,
        ?CacheItemPoolInterface $metadatenCache = null
    ): EntityManager {
        $config = new Configuration();

        // Eine Chain statt eines Treibers mit zwei Pfaden: So bleibt die Zuordnung
        // Namensraum → Verzeichnis ausdrücklich, statt sie Doctrine über die Dateinamen
        // erraten zu lassen. Genau das tat dflydev auch.
        //
        // `Doctrine\Persistence\…\MappingDriverChain` gibt es in persistence 1.3 UND 2/3 —
        // deshalb läuft diese Klasse gegen den alten wie den neuen Baum. Der alte Namensraum
        // `Doctrine\Common\Persistence\…` existiert in 2.0 nicht mehr; genau daran ist
        // dflydev gescheitert.
        $chain = new MappingDriverChain();

        foreach ($mappings as $mapping) {
            // ATTRIBUTE STATT ANNOTATIONEN, FUER ALLE NAMENSRAEUME (010-001-0003).
            //
            // Hier stand `$config->newDefaultAnnotationDriver($pfad, false)`.
            //
            // ES GEHT NUR GEMEINSAM, und das ist gemessen: Doctrine liest einen Namensraum mit
            // genau EINEM Treiber, also schien ein Schnitt je Namensraum moeglich —
            // `Areanet\PIM\Entity` zuerst, `Custom\Entity` danach. Er traegt nicht.
            // `Custom\Entity\Core\Example` erbt von `Areanet\PIM\Entity\Base`, und bei einer
            // MappedSuperclass setzt Doctrine an den geerbten Feldern KEIN `inherited`
            // (`ClassMetadataFactory::addMappingInheritanceInformation()`). Der Treiber der
            // Unterklasse liest sie deshalb NEU — ein Annotation-Treiber findet an einer
            // umgestellten Oberklasse nichts mehr und meldet
            //
            //     No identifier/primary key specified for Entity "Custom\Entity\Core\Example"
            //     sub class of "Areanet\PIM\Entity\Base".
            //
            // Ein Wahlschalter je Mapping stand hier deshalb kurz und ist wieder entfallen: Er
            // haette eine Freiheit angeboten, die es nicht gibt.
            $chain->addDriver(new AttributeDriver(array($mapping['path'])), $mapping['namespace']);
        }

        $config->setMetadataDriverImpl($chain);

        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('DoctrineProxy');
        $config->setAutoGenerateProxyClasses($autoGenerateProxies);

        foreach ($numericFunctions as $name => $klasse) {
            $config->addCustomNumericFunction($name, $klasse);
        }

        /*
         * DIE CACHES GEHOEREN HIERHER, NICHT IN DEN BOOTSTRAP (010-002-0005).
         *
         * Bis hierher setzte bootstrap.php sie auf der Konfiguration, NACHDEM diese Methode
         * den EntityManager gebaut hatte. Fuer den Abfrage-Cache ging das gut: Doctrine liest
         * `getQueryCache()` bei jeder Abfrage. Der Metadaten-Cache dagegen wird GENAU EINMAL
         * gelesen — in `EntityManager::__construct()`, ueber `configureMetadataCache()`. Was
         * danach kommt, sieht die ClassMetadataFactory nie.
         *
         * Gemessen, ohne Datenbank: Cache vor dem EntityManager gesetzt -> 2 Cache-Dateien
         * nach einer Metadaten-Abfrage; danach gesetzt -> 0. Und im Testlauf gegen den echten
         * Server blieb `data/cache/metadata` leer, mit doctrine/cache genauso wie mit PSR-6 —
         * der Metadaten-Cache hat also nie gegriffen.
         *
         * `null` heisst: kein Cache. Der Aufrufer entscheidet das, nicht diese Methode — im
         * Debug-Modus und auf der Konsole soll keiner laufen, sonst arbeitet ein Entwickler
         * gegen veraltete Metadaten.
         */
        if ($abfrageCache !== null) {
            $config->setQueryCache($abfrageCache);
        }

        if ($metadatenCache !== null) {
            $config->setMetadataCache($metadatenCache);
        }

        // `new EntityManager(...)` STATT `EntityManager::create(...)` (010-003-0001).
        //
        // Die statische Fabrik ist in ORM 3 entfernt; in 2.20 ist sie deprecated und der
        // Konstruktor bereits public. Deshalb steht die Umstellung HIER, vor dem
        // Versionssprung: Sie laesst sich gegen ein unveraendertes ORM messen.
        //
        // Sie war der erste von zwei Blockern des Sprungs — an dieser Zeile starb der ganze
        // Baum, 196 von 268 Tests (Messung im Story-Text).
        $em = new EntityManager($connection, $config);

        /*
         * DER modified_index-LISTENER GEHOERT HIERHER (010-005-0002).
         *
         * Er haengt jeder Entity einen Index auf `modified` an. Registriert wurde er in
         * bootstrap.php — INNERHALB von `if($app['is_installed'])`. `appcms:install` laeuft
         * aber genau dann, wenn is_installed FALSCH ist: Der Listener griff dort nie, der Index
         * wurde nie angelegt, und `orm:validate-schema` meldete seither bei JEDER Installation,
         * dass Schema und Mapping nicht deckungsgleich sind.
         *
         * DAS IST DIE DRITTE AUFLAGE DESSELBEN FEHLERS IN EPIC 010, und deshalb steht die
         * Registrierung jetzt hier statt beim Aufrufer:
         *
         *   010-002-0005   Der Metadaten-Cache wurde nach dem EntityManager gesetzt und
         *                  erreichte die ClassMetadataFactory nie.
         *   010-003-0002   Der Installer wiederholte den Mapping-Block aus bootstrap.php,
         *                  und die Wiederholung wich ab.
         *   010-005-0002   Dieser Listener.
         *
         * Die Regel dahinter: Was fuer JEDEN EntityManager gelten muss, gehoert in die Factory.
         * Steht es beim Aufrufer, muss es dort mehrfach stehen — und irgendwo fehlt es dann.
         */
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new LoadMetadata());

        return $em;
    }
}
