<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Areanet\PIM\Classes\Kernel\Routing\RouteSecurityListener;
use Areanet\PIM\Classes\Kernel\Routing\ControllerResolver;
use Areanet\PIM\Classes\Kernel\Routing\RouteEntry;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The framework's application on a Symfony 7.4 kernel (009-002-0002).
 *
 * IT **IS** THE CONTAINER, as `Silex\Application` was: it extends `Container` and thereby brings
 * `$app['orm.em']`, `$app['schema']` and a project's services along. That is not a convenience but
 * the contract `custom/app.php` depends on — which is why the `ApplicationInterface` from
 * `009-001-0001` already described it beforehand. **It has stayed unchanged word for word with
 * this task.**
 *
 * WHAT SITS BENEATH THE SURFACE IS NEW. Instead of Silex's kernel:
 *
 * - `Symfony\Component\HttpKernel\HttpKernel` — accepts a request and returns a response, via
 *   `kernel.request`, `kernel.controller`, `kernel.view`, `kernel.response`.
 * - `RouterListener` — sets `_controller` and the path placeholders from the `RouteCollection` on
 *   the request. In Silex the `ControllerCollection` did that.
 * - `ControllerResolver` and `ArgumentResolver` — turn `_controller` into a callable method and
 *   fill in its arguments.
 *
 * `before()`, `after()` and `error()` remain as calls because `custom/app.php` uses them;
 * underneath they are listeners on `kernel.request`, `kernel.response` and `kernel.exception`.
 * **Their finer points — ordering by priority and the shape of the error response — belong to
 * `009-002-0004`**, which proves them against the characterization tests. They live here so that
 * the kernel runs at all.
 */
class Application extends Container implements ApplicationInterface
{
    /** The collected routes. `mount()` fills them, the matcher reads them. */
    private RouteCollection $routes;

    private bool $booted = false;

    public function __construct(bool $debug = false)
    {
        $this->routes = new RouteCollection();

        $this['debug'] = $debug;

        $this['request_stack'] = static function (): RequestStack {
            return new RequestStack();
        };

        $this['dispatcher'] = static function (): EventDispatcher {
            return new EventDispatcher();
        };

        /*
         * The resolver understands the form `service:method` — what Silex's
         * ServiceControllerServiceProvider used to provide (009-002-0003).
         */
        $this['resolver'] = static function (Container $app): ControllerResolver {
            return new ControllerResolver($app);
        };

        $this['argument_resolver'] = static function (): ArgumentResolver {
            return new ArgumentResolver();
        };

        $this['kernel'] = function (Container $app): HttpKernel {
            return new HttpKernel(
                $app['dispatcher'],
                $app['resolver'],
                $app['request_stack'],
                $app['argument_resolver'],
                /*
                 * handleAllThrowables: true — and that is not a detail (009-002-0004).
                 *
                 * By default Symfony's HttpKernel only catches `\Exception`. A TypeError then
                 * falls through the whole kernel, and the caller gets an empty 500 — **exactly
                 * the finding from 000-000-0006**, just with Symfony's kernel instead of Silex's
                 * ExceptionListenerWrapper.
                 *
                 * Measured: with the default, `POST /api/update` with `data` as a string returns
                 * a body of 0 bytes; with `true`, the application's JSON response.
                 * FehlerantwortApiTest checks both halves.
                 */
                true
            );
        };
    }

    // ── Routes ─────────────────────────────────────────────────────────────────────────

    /**
     * Mounts a bundle of routes under a path.
     *
     * Expects a `RouteCollection` — what `Kernel\ControllerProviderInterface::connect()`
     * returns. Until `009-002-0003` that was still a `Silex\ControllerCollection`; the switch is
     * exactly what that task was about.
     */
    public function mount($prefix, $controllers)
    {
        if (!$controllers instanceof RouteCollection) {
            throw new \LogicException(sprintf(
                'mount() expects a RouteCollection, got: %s. '
                .'A controller provider returns one from connect().',
                get_debug_type($controllers)
            ));
        }

        /*
         * The prefix is normalised because callers write it differently: bootstrap-web.php
         * mounts '/api', custom/app.php 'api/v1/example/'. Silex accepted both.
         */
        $pathPrefix = '/'.trim($prefix, '/');
        $controllers->addPrefix($pathPrefix);

        /*
         * THE NAME PREFIX IS NOT COSMETIC (013-001-0005).
         *
         * `RouteCollector` numbers its routes **per provider**: the first route of
         * ApiControllerProvider is called `login_0`, and so is the first route of
         * AuthControllerProvider. `RouteCollection::addCollection()` overwrites by name — the
         * collection mounted later silently displaces the one mounted earlier.
         *
         * MEASURED: 30 registered routes, 29 in the collection. Missing were `POST /api/login`
         * and `POST /api/logout` — both displaced by their namesakes under `/auth`. They were
         * considered "dead routes pointing to methods that do not exist"; in truth they never
         * reached the router, and a call ended in the OPTIONS catch-all with 405, not in the
         * controller resolver.
         *
         * The prefix comes from the mount point and makes the names unique across all
         * collections. Nobody uses the names themselves — there is no `url_generator` — but they
         * have to be unique, otherwise mounting is a gamble.
         */
        $controllers->addNamePrefix(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $pathPrefix), '_').'_');

        $this->routes->addCollection($controllers);

        return $this;
    }

    /** The collected routes — for the matcher and for tests. */
    public function routes(): RouteCollection
    {
        return $this->routes;
    }

    /**
     * A route directly on the application, without a provider — for OPTIONS only.
     *
     * `bootstrap-web.php` uses it to create the CORS preflight catch-all. It is the only route in
     * the tree not built by a controller provider, and so this stays the only method of its kind:
     * whoever wants an ordinary route uses the `RouteManager`.
     */
    public function options(string $path, callable $callback): RouteEntry
    {
        $route = new Route('/'.ltrim($path, '/'), array('_controller' => $callback));
        $route->setMethods(array('OPTIONS'));

        $this->routes->add('options_'.count($this->routes), $route);

        return new RouteEntry($route);
    }

    // ── Hooks ──────────────────────────────────────────────────────────────────────────

    /**
     * Hook before the action.
     *
     * The callback receives `(Request $request, Application $app)` as in Silex. If it returns a
     * `Response`, processing stops — that is the documented way to block a request
     * (`custom/app.php`).
     */
    public function before($callback, $priority = 0)
    {
        $this->on(
            \Symfony\Component\HttpKernel\KernelEvents::REQUEST,
            function (RequestEvent $event) use ($callback): void {
                if (!$event->isMainRequest()) {
                    return;
                }

                $result = $callback($event->getRequest(), $this);

                if ($result instanceof Response) {
                    $event->setResponse($result);
                }
            },
            $priority
        );
    }

    /** Hook after the action. The callback receives `(Request, Response)` as in Silex. */
    public function after($callback, $priority = 0)
    {
        $this->on(
            \Symfony\Component\HttpKernel\KernelEvents::RESPONSE,
            function (ResponseEvent $event) use ($callback): void {
                $result = $callback($event->getRequest(), $event->getResponse(), $this);

                if ($result instanceof Response) {
                    $event->setResponse($result);
                }
            },
            $priority
        );
    }

    /**
     * Error handling.
     *
     * The default priority is −8 as in Silex: it lets listeners with a higher priority go first
     * and runs before anything attached further down.
     */
    public function error($callback, $priority = -8)
    {
        $this->on(
            \Symfony\Component\HttpKernel\KernelEvents::EXCEPTION,
            function (ExceptionEvent $event) use ($callback): void {
                if ($event->hasResponse()) {
                    return;
                }

                $result = $callback($event->getThrowable(), $event->getRequest());

                if ($result instanceof Response) {
                    $event->setResponse($result);
                }
            },
            $priority
        );
    }

    /**
     * A listener on any kernel event.
     *
     * BEFORE BOOT THE REGISTRATION IS DEFERRED, NOT EXECUTED (009-004-0004).
     *
     * The reason is container freezing: whoever reads `$this['dispatcher']` freezes it, and every
     * later `extend('dispatcher', …)` throws. The `ConsoleManager` needs exactly this `extend()`
     * to register project commands. A `before()` in `custom/app.php` — the file in which a
     * project does both — would therefore have made every command registered after it
     * impossible:
     *
     *     RuntimeException: The service "dispatcher" has already been read …
     *
     * Silex solved it the same way and routed `on()` through `extend()` before boot. It got lost
     * when this was rebuilt in `009-002-0002` — the method looked simpler, and no test covered the
     * sequence "first a hook, then a command". It only surfaced when the template followed its own
     * documented path in `009-004-0001`.
     *
     * The execution order does not change: the deferred registrations run on the first
     * `handle()` in the same order and with the same priorities.
     */
    public function on($eventName, $callback, $priority = 0)
    {
        if ($this->booted) {
            $this['dispatcher']->addListener($eventName, $callback, $priority);

            return;
        }

        $this->extend('dispatcher', static function ($dispatcher) use ($eventName, $callback, $priority) {
            $dispatcher->addListener($eventName, $callback, $priority);

            return $dispatcher;
        });
    }

    // ── Response factories ─────────────────────────────────────────────────────────────

    public function json($data = array(), $status = 200, array $headers = array()): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function redirect($url, $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    // ── Execution ──────────────────────────────────────────────────────────────────────

    /**
     * Attaches the routes to the kernel — once, on the first `handle()`.
     *
     * Later than in the constructor because `mount()` is called until right before the first
     * request: `bootstrap.php` reads `custom/app.php` and then calls
     * `$app['routeManager']->bindRoutes()`.
     */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        /*
         * booted FIRST: from here on on() runs directly instead of through extend(), and the lines
         * below read the dispatcher — which resolves the deferred registrations. The other way
         * round, the first access would call on() recursively.
         */
        $this->booted = true;

        $this['dispatcher']->addSubscriber(new RouterListener(
            new UrlMatcher($this->routes, new RequestContext()),
            $this['request_stack']
        ));

        /*
         * Per-route protection. Priority 0 on kernel.controller: the router has already matched
         * the route, the controller is resolved but has not run yet.
         */
        $this['dispatcher']->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::CONTROLLER,
            new RouteSecurityListener($this)
        );
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->boot();

        return $this['kernel']->handle($request, $type, $catch);
    }

    /** Takes the request from the globals, answers it and sends the response. */
    public function run(?Request $request = null): void
    {
        if ($request === null) {
            $request = Request::createFromGlobals();
        }

        $response = $this->handle($request);
        $response->send();

        $this['kernel']->terminate($request, $response);
    }
}
