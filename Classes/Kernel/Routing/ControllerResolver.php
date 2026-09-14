<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver as SymfonyControllerResolver;

/**
 * Resolves `service:method` against the container (009-002-0003).
 *
 * THIS IS WHAT SILEX'S `ServiceControllerServiceProvider` USED TO PROVIDE. All routes of the
 * framework and the template name their controller like this:
 *
 *     $controllers->post('/list', "api.controller:listAction");
 *
 * On the left the container key, on the right the method. The key itself is created by the
 * provider — `$app['api.controller'] = function ($app) { return new ApiController($app); }` — and
 * it matters that it is a **lazy factory**: the controller is only created when a route hits it,
 * not when the routes are registered.
 *
 * Symfony's own resolver understands `Class::method` with two colons. The single-colon form is
 * Silex's invention and is intercepted here before Symfony gets to see it; everything else is
 * passed on unchanged.
 */
class ControllerResolver extends SymfonyControllerResolver
{
    public function __construct(private readonly ApplicationInterface $app)
    {
        parent::__construct();
    }

    protected function createController(string $controller): callable
    {
        // One colon, not two: otherwise it is Symfony's own form Class::method.
        if (substr_count($controller, ':') === 1) {
            [$service, $method] = explode(':', $controller, 2);

            if (isset($this->app[$service])) {
                return array($this->app[$service], $method);
            }

            throw new \InvalidArgumentException(sprintf(
                'The controller "%s" refers to the container key "%s", which does not exist. '
                .'A controller provider creates it in connect().',
                $controller,
                $service
            ));
        }

        return parent::createController($controller);
    }
}
