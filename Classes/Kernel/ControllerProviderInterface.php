<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Routing\RouteCollection;

/**
 * A bundle of routes that can be mounted under a path (009-001-0003).
 *
 * The framework's own replacement for `Silex\Api\ControllerProviderInterface`. Why its own,
 * although the signature is the same: Silex's version prescribes `connect(Silex\Application
 * $app)`. PHP only allows an implementation to **widen** the parameter type, not to replace it —
 * and `Silex\Application` does not satisfy `ApplicationInterface`. As long as the five providers
 * implement Silex's interface, they have to name Silex in their signature. Hence an own one.
 *
 * THE RETURN TYPE HAS BEEN THERE SINCE `009-002-0003`. `009-001-0003` deliberately left it open:
 * back then `connect()` returned a `Silex\ControllerCollection`, and declaring a type would have
 * pinned Silex down exactly where it was about to disappear next. Now it is a Symfony
 * `RouteCollection` — exactly what `Application::mount()` accepts.
 *
 * In practice the providers return a `Routing\RouteCollector`, which extends it and adds `get()`,
 * `post()` and `match()`. The type here stays the more general `RouteCollection` so that a project
 * can build its routes differently as well.
 *
 * CONSEQUENCE FOR MOUNTING. The `RouteManager` calls `connect()` itself and passes the finished
 * collection to `mount()` — since `009-001-0003`, where the providers lost Silex's interface and
 * its `mount()` therefore no longer recognised them as providers. The flow does not change here.
 */
interface ControllerProviderInterface
{
    /** Builds this provider's routes and returns them as a bundle. */
    public function connect(ApplicationInterface $app): RouteCollection;
}
