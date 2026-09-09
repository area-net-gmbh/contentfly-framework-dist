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
use Areanet\PIM\Classes\Kernel\Routing\AbsicherungListener;
use Areanet\PIM\Classes\Kernel\Routing\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Die Anwendung des Frameworks auf einem Symfony-7.4-Kernel (009-002-0002).
 *
 * SIE **IST** DER CONTAINER, wie `Silex\Application` es war: Sie erbt von `Container` und
 * bringt darüber `$app['orm.em']`, `$app['schema']` und die Dienste eines Projekts mit. Das ist
 * keine Bequemlichkeit, sondern der Vertrag, an dem `custom/app.php` hängt — und die
 * Schnittstelle `ApplicationInterface` aus `009-001-0001` hat ihn deshalb schon vorher
 * beschrieben. **Sie ist mit diesem Task Wort für Wort unverändert geblieben.**
 *
 * WAS UNTER DER OBERFLÄCHE STEHT, IST NEU. Statt Silex' Kernel:
 *
 * - `Symfony\Component\HttpKernel\HttpKernel` — nimmt einen Request entgegen und liefert eine
 *   Response, über `kernel.request`, `kernel.controller`, `kernel.view`, `kernel.response`.
 * - `RouterListener` — setzt `_controller` und die Pfadplatzhalter aus der `RouteCollection`
 *   auf den Request. Das übernimmt in Silex die `ControllerCollection`.
 * - `ControllerResolver` und `ArgumentResolver` — machen aus `_controller` eine aufrufbare
 *   Methode und füllen ihre Argumente.
 *
 * `before()`, `after()` und `error()` bleiben als Aufrufe erhalten, weil `custom/app.php` sie
 * benutzt; darunter sind es Listener auf `kernel.request`, `kernel.response` und
 * `kernel.exception`. **Ihre Feinheiten — die Reihenfolge mit Prioritäten und die Form der
 * Fehlerantwort — gehören `009-002-0004`**, das sie gegen die Charakterisierungstests
 * nachweist. Hier stehen sie, damit der Kernel überhaupt läuft.
 */
class Application extends Container implements ApplicationInterface
{
    /** Die gesammelten Routen. `mount()` füllt sie, der Matcher liest sie. */
    private RouteCollection $routen;

    private bool $gebootet = false;

    public function __construct(bool $debug = false)
    {
        $this->routen = new RouteCollection();

        $this['debug'] = $debug;

        $this['request_stack'] = static function (): RequestStack {
            return new RequestStack();
        };

        $this['dispatcher'] = static function (): EventDispatcher {
            return new EventDispatcher();
        };

        /*
         * Der Resolver kennt die Form `dienst:methode` — das, was der
         * ServiceControllerServiceProvider von Silex geliefert hat (009-002-0003).
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
                $app['argument_resolver']
            );
        };
    }

    // ── Routen ─────────────────────────────────────────────────────────────────────────

    /**
     * Hängt ein Bündel Routen unter einen Pfad.
     *
     * Erwartet eine `RouteCollection` — was `Kernel\ControllerProviderInterface::connect()`
     * liefert. Bis `009-002-0003` gab es dort noch eine `Silex\ControllerCollection`; die
     * Umstellung ist genau der Inhalt jenes Tasks.
     */
    public function mount($prefix, $controllers)
    {
        if (!$controllers instanceof RouteCollection) {
            throw new \LogicException(sprintf(
                'mount() erwartet eine RouteCollection, bekommen: %s. '
                .'Ein Controller-Provider liefert sie ueber connect().',
                get_debug_type($controllers)
            ));
        }

        /*
         * Der Praefix wird normalisiert, weil die Aufrufer ihn verschieden schreiben:
         * bootstrap-web.php mountet '/api', custom/app.php 'api/v1/example/'. Silex hat beides
         * angenommen.
         */
        $controllers->addPrefix('/'.trim($prefix, '/'));
        $this->routen->addCollection($controllers);

        return $this;
    }

    /** Die gesammelten Routen — für den Matcher und für Tests. */
    public function routen(): RouteCollection
    {
        return $this->routen;
    }

    // ── Hooks ──────────────────────────────────────────────────────────────────────────

    /**
     * Hook vor der Action.
     *
     * Der Rückruf bekommt `(Request $request, Application $app)` wie in Silex. Gibt er eine
     * `Response` zurück, bricht die Verarbeitung ab — das ist der dokumentierte Weg, einen
     * Request zu blockieren (`custom/app.php`).
     */
    public function before($callback, $priority = 0)
    {
        $this['dispatcher']->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::REQUEST,
            function (RequestEvent $event) use ($callback): void {
                if (!$event->isMainRequest()) {
                    return;
                }

                $ergebnis = $callback($event->getRequest(), $this);

                if ($ergebnis instanceof Response) {
                    $event->setResponse($ergebnis);
                }
            },
            $priority
        );
    }

    /** Hook nach der Action. Der Rückruf bekommt `(Request, Response)` wie in Silex. */
    public function after($callback, $priority = 0)
    {
        $this['dispatcher']->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::RESPONSE,
            function (ResponseEvent $event) use ($callback): void {
                $ergebnis = $callback($event->getRequest(), $event->getResponse(), $this);

                if ($ergebnis instanceof Response) {
                    $event->setResponse($ergebnis);
                }
            },
            $priority
        );
    }

    /**
     * Fehlerbehandlung.
     *
     * Die Vorgabe-Priorität ist −8 wie in Silex: Sie lässt Listenern mit höherer Priorität den
     * Vortritt und läuft vor allem, was noch weiter unten hängt.
     */
    public function error($callback, $priority = -8)
    {
        $this['dispatcher']->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::EXCEPTION,
            function (ExceptionEvent $event) use ($callback): void {
                if ($event->hasResponse()) {
                    return;
                }

                $ergebnis = $callback($event->getThrowable(), $event->getRequest());

                if ($ergebnis instanceof Response) {
                    $event->setResponse($ergebnis);
                }
            },
            $priority
        );
    }

    /** Ein Listener auf ein beliebiges Kernel-Ereignis. */
    public function on($eventName, $callback, $priority = 0)
    {
        $this['dispatcher']->addListener($eventName, $callback, $priority);
    }

    // ── Antwort-Fabriken ───────────────────────────────────────────────────────────────

    public function json($data = array(), $status = 200, array $headers = array()): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function redirect($url, $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    // ── Ausführung ─────────────────────────────────────────────────────────────────────

    /**
     * Hängt die Routen an den Kernel — einmal, beim ersten `handle()`.
     *
     * Später als im Konstruktor, weil `mount()` bis unmittelbar vor dem ersten Request
     * aufgerufen wird: `bootstrap.php` liest `custom/app.php` und ruft danach
     * `$app['routeManager']->bindRoutes()`.
     */
    private function boot(): void
    {
        if ($this->gebootet) {
            return;
        }

        $this->gebootet = true;

        $this['dispatcher']->addSubscriber(new RouterListener(
            new UrlMatcher($this->routen, new RequestContext()),
            $this['request_stack']
        ));

        /*
         * Die Absicherung pro Route. Prioritaet 0 auf kernel.controller: Der Router hat die
         * Route schon zugeordnet, der Controller ist aufgeloest, aber noch nicht gelaufen.
         */
        $this['dispatcher']->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::CONTROLLER,
            new AbsicherungListener($this)
        );
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->boot();

        return $this['kernel']->handle($request, $type, $catch);
    }

    /** Nimmt den Request aus den Globals, beantwortet ihn und schickt die Antwort. */
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
