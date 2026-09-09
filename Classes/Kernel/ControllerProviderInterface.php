<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Routing\RouteCollection;

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
 * DER RÜCKGABETYP IST SEIT `009-002-0003` DA. `009-001-0003` hatte ihn bewusst offengelassen:
 * Damals lieferte `connect()` eine `Silex\ControllerCollection`, und einen Typ zu setzen hätte
 * Silex an genau der Stelle festgeschrieben, an der er als Nächstes verschwand. Jetzt ist es
 * eine `RouteCollection` von Symfony — genau das, was `Application::mount()` entgegennimmt.
 *
 * In der Praxis geben die Provider eine `Routing\Routensammlung` zurück, die davon erbt und
 * `get()`, `post()` und `match()` mitbringt. Der Typ hier bleibt die allgemeinere
 * `RouteCollection`, damit ein Projekt seine Routen auch anders bauen kann.
 *
 * FOLGE FÜR DAS MOUNTEN. Der `RouteManager` ruft `connect()` selbst auf und übergibt die
 * fertige Sammlung an `mount()` — seit `009-001-0003`, wo die Provider Silex' Schnittstelle
 * verloren haben und dessen `mount()` sie deshalb nicht mehr als Provider erkannte. Am Ablauf
 * ändert sich hier nichts.
 */
interface ControllerProviderInterface
{
    /** Baut die Routen dieses Providers und gibt sie gebündelt zurück. */
    public function connect(ApplicationInterface $app): RouteCollection;
}
