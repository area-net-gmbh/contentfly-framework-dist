<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of one controller provider (009-002-0003).
 *
 * REPLACES `$app['controllers_factory']`, i.e. Silex's `ControllerCollection`. It provides exactly
 * the three methods the providers actually use — `get()`, `post()` and `match()` — and returns
 * the finished `RouteCollection` that `Application::mount()` accepts via `collection()`. Nothing
 * else; Silex's collection could do far more, and whatever nobody calls does not need to be
 * rebuilt.
 *
 * IT DOES NOT EXTEND `RouteCollection`, AND THAT IS NOT A MATTER OF TASTE. The first draft did —
 * then `mount()` receives a finished collection without anyone having to unwrap it. PHP rejected
 * that immediately:
 *
 *     Declaration of RouteCollector::get(string $path, string $controller): RouteEntry
 *     must be compatible with RouteCollection::get(string $name): ?Route
 *
 * `RouteCollection::get()` fetches a route **by name**; the providers mean the HTTP verb by
 * `get()`. Two different meanings for the same name, and the inherited one wins. The collector
 * therefore contains a `RouteCollection` instead of being one.
 *
 * WHY THERE IS NO CONTAINER KEY ANY MORE. `$app['controllers_factory']` had to return a fresh
 * collection on **every** access — Pimple had `factory()` for that. The framework's own container
 * remembers results; a key of that kind would be an exception to its basic rule. A provider now
 * writes `new RouteCollector()`, which says what happens anyway.
 *
 * THE ROUTE NAMES are numbered and combined with the path. They do not appear anywhere in the
 * tree — there is no `url_generator` and no call naming a route — but they have to be unique
 * within a `RouteCollection`. The counter is needed because `FileControllerProvider` puts seven
 * routes on **the same** action.
 */
class RouteCollector
{
    private RouteCollection $routes;

    private int $counter = 0;

    public function __construct()
    {
        $this->routes = new RouteCollection();
    }

    /** The finished collection, as `Application::mount()` expects it. */
    public function collection(): RouteCollection
    {
        return $this->routes;
    }

    /** @param string $controller in the format `service:method` */
    public function get(string $path, string $controller): RouteEntry
    {
        return $this->add(array('GET'), $path, $controller);
    }

    /** @param string $controller in the format `service:method` */
    public function post(string $path, string $controller): RouteEntry
    {
        return $this->add(array('POST'), $path, $controller);
    }

    /**
     * A route without a method restriction.
     *
     * `Route::MATCH` in the template; a project uses it to register an endpoint that answers to
     * every verb.
     *
     * @param string $controller in the format `service:method`
     */
    public function match(string $path, string $controller): RouteEntry
    {
        return $this->add(array(), $path, $controller);
    }

    /** @param array<int,string> $methods empty means: any method */
    private function add(array $methods, string $path, string $controller): RouteEntry
    {
        /*
         * A leading slash, exactly one. CustomControllerProvider builds the path as
         * '/'.$route->route, and a project writes its route with or without a leading slash —
         * custom/app.php writes it with one. Silex normalised that; without it the result would be
         * '//bootstrap', which no request ever matches.
         */
        $route = new Route('/'.ltrim($path, '/'), array('_controller' => $controller));
        $route->setMethods($methods);

        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $path), '_').'_'.$this->counter++;
        $this->routes->add($name, $route);

        return new RouteEntry($route);
    }
}
