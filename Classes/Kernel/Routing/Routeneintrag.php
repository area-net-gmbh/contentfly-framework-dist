<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Symfony\Component\Routing\Route;

/**
 * Eine einzelne Route, solange sie noch beschrieben wird (009-002-0003).
 *
 * Existiert für genau einen Zweck: `->before($checkAuth)` an eine Route zu hängen, so wie es
 * die fünf Controller-Provider seit jeher schreiben. Silex konnte das, weil seine
 * `ControllerCollection` einen `Controller` zurückgab; Symfonys `RouteCollection::add()` gibt
 * nichts zurück.
 *
 * **Das ist die Absicherung pro Route** — `Route::$isSecure` in der Vorlage,
 * `$checkAuth` in den Providern des Frameworks. `012-006-0003` hat ausdrücklich festgehalten,
 * dass sie das Erste ist, was beim Umschreiben von Routen verlorengeht, und
 * `RouteSecurityApiTest` prüft beide Richtungen.
 *
 * Der Rückruf landet als `_before` in den Defaults der Route und wird von
 * `AbsicherungListener` ausgeführt, nachdem der Router die Route zugeordnet hat und bevor der
 * Controller läuft.
 */
class Routeneintrag
{
    public function __construct(private readonly Route $route)
    {
    }

    /**
     * Ein Rückruf, der vor dieser einen Action läuft.
     *
     * Er bekommt `(Request $request, ApplicationInterface $app)`. Wirft er, ist der Zugriff
     * abgewiesen; gibt er eine `Response` zurück, wird die ausgeliefert und die Action läuft
     * nicht. Beides ist das Verhalten von Silex, und beides wird gebraucht: Die Provider
     * werfen, `custom/app.php` beschreibt das Zurückgeben.
     */
    public function before(callable $callback): self
    {
        $vorher = $this->route->getDefault('_before') ?? array();
        $vorher[] = $callback;
        $this->route->setDefault('_before', $vorher);

        return $this;
    }

    public function route(): Route
    {
        return $this->route;
    }
}
