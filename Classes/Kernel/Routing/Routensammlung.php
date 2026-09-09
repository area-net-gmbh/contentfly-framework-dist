<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Die Routen eines Controller-Providers (009-002-0003).
 *
 * ERSETZT `$app['controllers_factory']`, also Silex' `ControllerCollection`. Sie bringt genau
 * die drei Methoden mit, die die Provider tatsächlich benutzen — `get()`, `post()` und
 * `match()` — und liefert über `sammlung()` die fertige `RouteCollection`, die
 * `Application::mount()` entgegennimmt. Nichts sonst; Silex' Sammlung konnte weit mehr, und was
 * niemand ruft, muss auch nicht nachgebaut werden.
 *
 * SIE ERBT NICHT VON `RouteCollection`, UND DAS IST KEINE GESCHMACKSFRAGE. Der erste Entwurf
 * tat es — dann liegt `mount()` eine fertige Sammlung vor, ohne dass jemand sie auspacken muss.
 * PHP hat das sofort abgelehnt:
 *
 *     Declaration of Routensammlung::get(string $pfad, string $controller): Routeneintrag
 *     must be compatible with RouteCollection::get(string $name): ?Route
 *
 * `RouteCollection::get()` holt eine Route **beim Namen**; die Provider meinen mit `get()` das
 * HTTP-Verb. Zwei verschiedene Bedeutungen für denselben Namen, und der geerbte gewinnt. Die
 * Sammlung enthält deshalb eine `RouteCollection`, statt eine zu sein.
 *
 * WARUM KEIN CONTAINER-SCHLÜSSEL MEHR. `$app['controllers_factory']` musste bei **jedem**
 * Zugriff eine frische Sammlung liefern — Pimple hatte dafür `factory()`. Der eigene Container
 * merkt sich Ergebnisse; ein Schlüssel dieser Art wäre dort eine Ausnahme von seiner
 * Grundregel. Ein Provider schreibt jetzt `new Routensammlung()`, was ohnehin sagt, was
 * passiert.
 *
 * DIE ROUTENNAMEN werden durchgezählt und mit dem Pfad zusammengesetzt. Sie tauchen nirgends
 * im Baum auf — es gibt keinen `url_generator` und keinen Aufruf, der eine Route beim Namen
 * nennt —, müssen aber innerhalb einer `RouteCollection` eindeutig sein. Der Zähler ist
 * nötig, weil `FileControllerProvider` sieben Routen auf **dieselbe** Action legt.
 */
class Routensammlung
{
    private RouteCollection $routen;

    private int $zaehler = 0;

    public function __construct()
    {
        $this->routen = new RouteCollection();
    }

    /** Die fertige Sammlung, wie `Application::mount()` sie erwartet. */
    public function sammlung(): RouteCollection
    {
        return $this->routen;
    }

    /** @param string $controller im Format `dienst:methode` */
    public function get(string $pfad, string $controller): Routeneintrag
    {
        return $this->eintragen(array('GET'), $pfad, $controller);
    }

    /** @param string $controller im Format `dienst:methode` */
    public function post(string $pfad, string $controller): Routeneintrag
    {
        return $this->eintragen(array('POST'), $pfad, $controller);
    }

    /**
     * Eine Route ohne Methodenbeschränkung.
     *
     * `Route::MATCH` in der Vorlage; ein Projekt registriert damit einen Endpunkt, der auf
     * jedes Verb antwortet.
     *
     * @param string $controller im Format `dienst:methode`
     */
    public function match(string $pfad, string $controller): Routeneintrag
    {
        return $this->eintragen(array(), $pfad, $controller);
    }

    /** @param array<int,string> $methoden leer heisst: jede Methode */
    private function eintragen(array $methoden, string $pfad, string $controller): Routeneintrag
    {
        /*
         * Fuehrender Schraegstrich, genau einer. CustomControllerProvider baut den Pfad als
         * '/'.$route->route zusammen, und ein Projekt schreibt seine Route mit oder ohne
         * fuehrenden Schraegstrich — custom/app.php tut es mit. Silex hat das normalisiert;
         * ohne das entstuende '//bootstrap', was keine Anfrage je trifft.
         */
        $route = new Route('/'.ltrim($pfad, '/'), array('_controller' => $controller));
        $route->setMethods($methoden);

        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $pfad), '_').'_'.$this->zaehler++;
        $this->routen->add($name, $route);

        return new Routeneintrag($route);
    }
}
