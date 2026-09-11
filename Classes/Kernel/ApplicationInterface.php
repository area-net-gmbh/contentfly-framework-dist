<?php
namespace Areanet\PIM\Classes\Kernel;

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
 *
 *   **Seit `007-003` ist sie dauerhaft zugesichert, mit fester Schluesselliste.** Welche
 *   Schluessel das sind, was nur bei installierter Anwendung existiert und was interne
 *   Verdrahtung bleibt, steht in `an_project/docs/dev-guide.md`; die Entscheidung samt
 *   verworfener Alternativen in `an_project/docs/architecture.md` unter *Key decisions*.
 *   Hier steht es bewusst nicht noch einmal — zwei Listen liefen auseinander.
 * - **`HttpKernelInterface`** — `handle()`. Kein Silex-Aufruf, sondern Symfonys eigener; der
 *   `ApiController` schickt damit zwei interne Sub-Requests (`replaceAction()`). Er bleibt
 *   über den Wechsel hinweg unverändert und wird deshalb geerbt statt neu erfunden.
 * - **`mount()`, `before()`, `after()`, `extend()`** — Silex beziehungsweise Pimple. Diese vier
 *   sind der eigentliche Grund für diese Schnittstelle.
 * WAS HIER STAND UND WIEDER WEG IST — `redirect()` und `stream()`. Beides Bequemlichkeiten von
 * Silex, deren ganzer Rumpf `return new RedirectResponse(...)` beziehungsweise
 * `return new StreamedResponse(...)` lautete. `009-001-0004` hat die drei Aufrufstellen im
 * `FileController` auf die Symfony-Klassen umgestellt; damit gab es keinen Aufrufer mehr, und
 * eine Zusicherung ohne Aufrufer ist Ballast, den `009-002` sonst nachbauen muesste.
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
}
