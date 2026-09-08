<?php
namespace Areanet\PIM\Classes\ORM;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
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
     */
    public static function erzeugen(
        Connection $connection,
        array $mappings,
        string $proxyDir,
        bool $autoGenerateProxies,
        array $numericFunctions = array()
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
            // `newDefaultAnnotationDriver()` statt eines selbst gebauten `AnnotationDriver`:
            // Die Methode registriert nebenbei den Loader der `AnnotationRegistry`, ohne den
            // der Reader die Doctrine-eigenen Annotationen nicht auflöst. Ein Treiber mit
            // frischem `AnnotationReader` scheitert am alten Baum mit
            //
            //     [Semantical Error] The annotation "@Doctrine\ORM\Mapping\Entity" … was
            //     never imported.
            //
            // — und zwar erst beim ersten Metadaten-Zugriff, nicht beim Bau. dflydev benutzte
            // dieselbe Methode; das ist kein Zufall, sondern der einzige Weg, der gegen beide
            // Doctrine-Stände funktioniert.
            //
            // Das zweite Argument ist `use_simple_annotation_reader`, im Framework überall
            // `false`. Ein dritter Parameter kam in Doctrine 2.20 dazu und bleibt auf seinem
            // Standardwert — deshalb wird er hier nicht übergeben.
            $chain->addDriver(
                $config->newDefaultAnnotationDriver(array($mapping['path']), false),
                $mapping['namespace']
            );
        }

        $config->setMetadataDriverImpl($chain);

        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('DoctrineProxy');
        $config->setAutoGenerateProxyClasses($autoGenerateProxies);

        foreach ($numericFunctions as $name => $klasse) {
            $config->addCustomNumericFunction($name, $klasse);
        }

        return EntityManager::create($connection, $config);
    }
}
