<?php
namespace Areanet\PIM\Classes\Kernel\Routing;

use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Führt die `->before()`-Rückrufe einer Route aus (009-002-0003).
 *
 * DAS IST DIE ABSICHERUNG PRO ROUTE, und sie ist der empfindlichste Teil des Routings. In
 * Silex hing sie am `Controller`-Objekt; hier steht sie als `_before` in den Defaults der
 * Route, und dieser Listener holt sie ab.
 *
 * ZEITPUNKT: `kernel.controller`. Da hat der Router die Route bereits zugeordnet — vorher
 * wüsste niemand, welche Rückrufe gelten — und der Controller ist aufgelöst, aber noch nicht
 * gelaufen. Genau dazwischen gehört die Prüfung.
 *
 * NUR IM HAUPTREQUEST. `ApiController::replaceAction()` schickt zwei interne Sub-Requests an
 * `/api/insert` beziehungsweise `/api/update` (`009-001-0004`). Liefe die Prüfung dort erneut,
 * müsste der Sub-Request den Token noch einmal mitbringen — er trägt aber nur, was
 * `Request::create()` ihm gegeben hat. In Silex lief der `before()`-Filter am Controller
 * ebenfalls nur für den Request, der die Route traf.
 */
class AbsicherungListener
{
    public function __construct(private readonly ApplicationInterface $app)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $rueckrufe = $event->getRequest()->attributes->get('_before');

        if (!is_array($rueckrufe)) {
            return;
        }

        foreach ($rueckrufe as $rueckruf) {
            $ergebnis = $rueckruf($event->getRequest(), $this->app);

            /*
             * Eine zurueckgegebene Response bricht ab. Der Controller laeuft dann nicht — genau
             * so blockiert custom/app.php einen Request, und so beschreibt es die Vorlage.
             */
            if ($ergebnis instanceof Response) {
                $event->setController(static fn (): Response => $ergebnis);

                return;
            }
        }
    }
}
