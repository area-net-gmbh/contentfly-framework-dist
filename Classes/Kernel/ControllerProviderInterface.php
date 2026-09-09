<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * Ein Bündel Routen, das sich unter einen Pfad hängen lässt (009-001-0003).
 *
 * Der eigene Ersatz für `Silex\Api\ControllerProviderInterface`. Warum ein eigener, obwohl
 * die Signatur dieselbe ist: Silex' Fassung schreibt `connect(Silex\Application $app)` vor.
 * PHP erlaubt einer Implementierung nur, den Parametertyp zu **erweitern**, nicht ihn zu
 * ersetzen — und `Silex\Application` erfüllt `ApplicationInterface` nicht. Solange die fünf
 * Provider Silex' Schnittstelle implementieren, müssen sie Silex im Kopf nennen. Also eine
 * eigene.
 *
 * DER RÜCKGABEWERT IST HEUTE NOCH EINE `Silex\ControllerCollection`, und deshalb steht hier
 * kein Rückgabetyp. Die Sammlung entsteht in den Implementierungen aus
 * `$app['controllers_factory']`; sie ist der eine Punkt, an dem die Provider den Kernel
 * wirklich brauchen, und `009-002` tauscht sie mit ihm. Einen Rückgabetyp jetzt zu setzen
 * hiesse, Silex an genau der Stelle festzuschreiben, an der er als Nächstes verschwindet.
 *
 * FOLGE FÜR DAS MOUNTEN. `Silex\Application::mount()` nimmt eine `ControllerCollection`, eine
 * `ControllerProviderInterface` **von Silex** oder ein Callable. Da die Provider Silex'
 * Schnittstelle nicht mehr implementieren, ruft der `RouteManager` `connect()` selbst auf und
 * übergibt die fertige Sammlung. Das Ergebnis ist dasselbe; der Umweg über Silex' Erkennung
 * entfällt.
 */
interface ControllerProviderInterface
{
    /**
     * Baut die Routen dieses Providers und gibt sie gebündelt zurück.
     *
     * @return mixed Heute eine `Silex\ControllerCollection` — siehe Klassenkommentar.
     */
    public function connect(ApplicationInterface $app);
}
