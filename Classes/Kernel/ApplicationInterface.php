<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Die Anwendung, wie das Framework sie benutzt — und nur das (009-001-0001).
 *
 * DIESE SCHNITTSTELLE IST GEMESSEN, NICHT ENTWORFEN. Sie enthält, was der eigene Code
 * ausserhalb des Bootstraps vom `$app`-Objekt tatsächlich aufruft, und nichts darüber hinaus.
 * `Silex\Application` bietet ein Vielfaches an; würde man das mitnehmen, wäre die Schnittstelle
 * eine Abschrift von Silex und der Kernel-Wechsel müsste sie erfüllen statt sie abzulösen.
 *
 * Woher die einzelnen Teile kommen:
 *
 * - **`ArrayAccess`** — der Container-Zugriff `$app['orm.em']`, `$app['schema']`,
 *   `$app['auth.user']` und die Factory-Registrierung `$app['dienst'] = function ($app) {…}`.
 *   Das ist die Oberfläche, an der auch Projekte hängen: `custom/app.php` registriert seine
 *   Dienste genau so. Sie zu erhalten ist der Zweck des `ArrayAccess`-Bridge aus `009-002`.
 * - **`HttpKernelInterface`** — `handle()`. Kein Silex-Aufruf, sondern Symfonys eigener; der
 *   `ApiController` schickt damit zwei interne Sub-Requests (`replaceAction()`). Er bleibt
 *   über den Wechsel hinweg unverändert und wird deshalb geerbt statt neu erfunden.
 * - **`mount()`, `before()`, `after()`, `extend()`** — Silex beziehungsweise Pimple. Diese vier
 *   sind der eigentliche Grund für diese Schnittstelle.
 * - **`redirect()`, `stream()`** — Bequemlichkeiten von Silex, die nichts tun, als eine
 *   Response zu bauen. Sie stehen hier **vorübergehend**: `009-001-0004` ersetzt die drei
 *   Aufrufstellen durch die Symfony-Klassen, die sie ohnehin zurückgeben, und nimmt die beiden
 *   Methoden danach wieder heraus. Bis dahin wäre die Schnittstelle sonst unvollständig.
 *
 * WAS BEWUSST FEHLT — `register()`. `InstallCommand::bootDoctrine()` ruft es, und es steht
 * damit in der gemessenen Oberfläche. Aufgenommen ist es trotzdem nicht: Sein Parametertyp ist
 * `Pimple\ServiceProviderInterface`. Eine Schnittstelle, die Pimple aus dem Code nehmen soll
 * und Pimple in der eigenen Signatur trägt, verfehlt ihren Zweck. Der Aufruf bleibt bis
 * `009-002` als benannte Ausnahme stehen; er registriert ohnehin Silex' eigenen
 * `DoctrineServiceProvider` und wird dort ersetzt.
 *
 * Ebenfalls nicht hier: `error()`, `json()`, `run()`, `options()`, `get()`. Die ruft
 * ausschliesslich `bootstrap-web.php` — die eine Stelle, die den Kernel kennen *darf*.
 */
interface ApplicationInterface extends \ArrayAccess, HttpKernelInterface
{
    /**
     * Hängt einen Controller-Provider unter einen Pfad.
     *
     * Aufgerufen von `RouteManager::bindRoutes()`, und zwar erst nach `custom/app.php` — die
     * Reihenfolge ist Absicht, siehe das Ende von `bootstrap.php`.
     */
    public function mount($prefix, $controllers);

    /**
     * Ersetzt eine bereits registrierte Container-Definition.
     *
     * Aufgerufen von `ConsoleManager::addCommand()`. **Der Zeitpunkt ist heikel:** Pimple
     * friert eine Definition ein, sobald sie einmal ausgelesen wurde, und wirft danach
     * `FrozenServiceException`. Genau darüber ist `000-000-0006` gestolpert.
     */
    public function extend($id, $callable);

    /** Hook vor der Action. Die Priorität ist Absicht, wo sie gesetzt ist — siehe technical.md. */
    public function before($callback, $priority = 0);

    /** Hook nach der Action. */
    public function after($callback, $priority = 0);

    /**
     * Vorübergehend, siehe Klassenkommentar: entfällt mit 009-001-0004.
     *
     * Ohne Rückgabetyp, obwohl es immer eine RedirectResponse ist: Silex' Methode deklariert
     * keinen, und eine Implementierung ohne Rückgabetyp erfüllt keine Signatur mit einem.
     * Die Angabe steht deshalb im @return, nicht in der Signatur.
     *
     * @return RedirectResponse
     */
    public function redirect($url, $status = 302);

    /**
     * Vorübergehend, siehe Klassenkommentar: entfällt mit 009-001-0004.
     *
     * @return StreamedResponse
     */
    public function stream($callback = null, $status = 200, array $headers = []);
}
