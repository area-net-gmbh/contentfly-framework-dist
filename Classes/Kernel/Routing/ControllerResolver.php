<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver as SymfonyControllerResolver;

/**
 * Löst `dienst:methode` gegen den Container auf (009-002-0003).
 *
 * DAS IST, WAS DER `ServiceControllerServiceProvider` VON SILEX GELIEFERT HAT. Alle Routen des
 * Frameworks und der Vorlage benennen ihren Controller so:
 *
 *     $controllers->post('/list', "api.controller:listAction");
 *
 * Links der Container-Schlüssel, rechts die Methode. Der Schlüssel selbst wird vom Provider
 * angelegt — `$app['api.controller'] = function ($app) { return new ApiController($app); }` —,
 * und dass er eine **faule Factory** ist, zählt: Der Controller entsteht erst, wenn eine Route
 * ihn trifft, nicht beim Registrieren der Routen.
 *
 * Symfonys eigener Resolver kennt `Klasse::methode` mit zwei Doppelpunkten. Die Form mit einem
 * ist Silex' Erfindung und wird hier abgefangen, bevor er sie zu sehen bekommt; alles andere
 * geht unverändert an ihn weiter.
 */
class ControllerResolver extends SymfonyControllerResolver
{
    public function __construct(private readonly ApplicationInterface $app)
    {
        parent::__construct();
    }

    protected function createController(string $controller): callable
    {
        // Ein Doppelpunkt, keine zwei: sonst ist es Symfonys eigene Form Klasse::methode.
        if (substr_count($controller, ':') === 1) {
            [$dienst, $methode] = explode(':', $controller, 2);

            if (isset($this->app[$dienst])) {
                return array($this->app[$dienst], $methode);
            }

            throw new \InvalidArgumentException(sprintf(
                'Der Controller "%s" verweist auf den Container-Schluessel "%s", den es nicht '
                .'gibt. Ein Controller-Provider legt ihn in connect() an.',
                $controller,
                $dienst
            ));
        }

        return parent::createController($controller);
    }
}
