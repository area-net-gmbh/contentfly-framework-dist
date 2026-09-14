<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Runs a route's `->before()` callbacks (009-002-0003).
 *
 * THIS IS THE PER-ROUTE PROTECTION, and it is the most sensitive part of routing. In Silex it hung
 * on the `Controller` object; here it lives as `_before` in the route's defaults, and this listener
 * picks it up.
 *
 * TIMING: `kernel.controller`. At that point the router has already matched the route — before
 * that nobody would know which callbacks apply — and the controller is resolved but has not run
 * yet. The check belongs exactly in between.
 *
 * MAIN REQUEST ONLY. `ApiController::replaceAction()` sends two internal sub-requests to
 * `/api/insert` and `/api/update` respectively (`009-001-0004`). If the check ran there again, the
 * sub-request would have to carry the token once more — but it only carries what
 * `Request::create()` gave it. In Silex the `before()` filter on the controller also only ran for
 * the request that hit the route.
 */
class RouteSecurityListener
{
    public function __construct(private readonly ApplicationInterface $app)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $callbacks = $event->getRequest()->attributes->get('_before');

        if (!is_array($callbacks)) {
            return;
        }

        foreach ($callbacks as $callback) {
            $result = $callback($event->getRequest(), $this->app);

            /*
             * A returned Response aborts. The controller then does not run — this is exactly how
             * custom/app.php blocks a request, and how the template describes it.
             */
            if ($result instanceof Response) {
                $event->setController(static fn (): Response => $result);

                return;
            }
        }
    }
}
