<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Symfony\Component\Routing\Route;

/**
 * A single route while it is still being described (009-002-0003).
 *
 * Exists for exactly one purpose: attaching `->before($checkAuth)` to a route, the way the five
 * controller providers have always written it. Silex could do that because its
 * `ControllerCollection` returned a `Controller`; Symfony's `RouteCollection::add()` returns
 * nothing.
 *
 * **This is the per-route protection** — `Route::$isSecure` in the template, `$checkAuth` in the
 * framework's providers. `012-006-0003` explicitly recorded that it is the first thing lost when
 * routes are rewritten, and `RouteSecurityApiTest` checks both directions.
 *
 * The callback ends up as `_before` in the route's defaults and is executed by
 * `RouteSecurityListener` after the router has matched the route and before the controller runs.
 */
class RouteEntry
{
    public function __construct(private readonly Route $route)
    {
    }

    /**
     * A callback that runs before this one action.
     *
     * It receives `(Request $request, ApplicationInterface $app)`. If it throws, access is denied;
     * if it returns a `Response`, that response is delivered and the action does not run. Both are
     * Silex's behaviour, and both are needed: the providers throw, `custom/app.php` describes
     * returning.
     */
    public function before(callable $callback): self
    {
        $previous = $this->route->getDefault('_before') ?? array();
        $previous[] = $callback;
        $this->route->setDefault('_before', $previous);

        return $this;
    }

    /**
     * A requirement on a path placeholder.
     *
     * Silex's name for Symfony's `requirements`. `bootstrap-web.php` uses it for the OPTIONS
     * catch-all: `->assert('anything', '.*')` lets the placeholder swallow slashes as well;
     * otherwise it only matches a single path segment.
     */
    public function assert(string $placeholder, string $pattern): self
    {
        $this->route->setRequirement($placeholder, $pattern);

        return $this;
    }

    public function route(): Route
    {
        return $this->route;
    }
}
