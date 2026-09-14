<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The application as the framework uses it — and nothing more (009-001-0001).
 *
 * THIS INTERFACE IS MEASURED, NOT DESIGNED. It contains what the framework's own code outside the
 * bootstrap actually calls on the `$app` object, and nothing beyond that. `Silex\Application`
 * offers many times more; taking all of that along would make the interface a copy of Silex, and
 * the kernel switch would have to fulfil it instead of replacing it.
 *
 * Where the individual parts come from:
 *
 * - **`ArrayAccess`** — container access `$app['orm.em']`, `$app['schema']`, `$app['auth.user']`
 *   and factory registration `$app['service'] = function ($app) {…}`. This is the surface
 *   projects depend on as well: `custom/app.php` registers its services exactly this way.
 *   Keeping it is the purpose of the `ArrayAccess` bridge from `009-002`.
 *
 *   **Since `007-003` it is guaranteed permanently, with a fixed list of keys.** Which keys those
 *   are, what only exists on an installed application and what stays internal wiring is listed
 *   in `an_project/docs/dev-guide.md`; the decision and the rejected alternatives are in
 *   `an_project/docs/architecture.md` under *Key decisions*. It is deliberately not repeated
 *   here — two lists would drift apart.
 * - **`HttpKernelInterface`** — `handle()`. Not a Silex call but Symfony's own; the
 *   `ApiController` uses it to send two internal sub-requests (`replaceAction()`). It stays
 *   unchanged across the switch and is therefore inherited rather than reinvented.
 * - **`mount()`, `before()`, `after()`, `extend()`** — Silex and Pimple respectively. These four
 *   are the actual reason for this interface.
 * WHAT USED TO BE HERE AND IS GONE AGAIN — `redirect()` and `stream()`. Both were Silex
 * conveniences whose entire body read `return new RedirectResponse(...)` and
 * `return new StreamedResponse(...)`. `009-001-0004` switched the three call sites in the
 * `FileController` to the Symfony classes; after that there was no caller left, and a guarantee
 * without a caller is ballast that `009-002` would otherwise have to rebuild.
 *
 * WHAT IS DELIBERATELY MISSING — `register()`. `InstallCommand::bootDoctrine()` calls it, so it
 * belongs to the measured surface. It is not included nonetheless: its parameter type is
 * `Pimple\ServiceProviderInterface`. An interface meant to remove Pimple from the code that
 * carries Pimple in its own signature misses its purpose. The call stays as a named exception
 * until `009-002`; it registers Silex's own `DoctrineServiceProvider` anyway and is replaced
 * there.
 *
 * Also not here: `error()`, `json()`, `run()`, `options()`, `get()`. Those are called only by
 * `bootstrap-web.php` — the one place that *may* know the kernel.
 */
interface ApplicationInterface extends \ArrayAccess, HttpKernelInterface
{
    /**
     * Mounts a controller provider under a path.
     *
     * Called by `RouteManager::bindRoutes()`, and only after `custom/app.php` — the order is
     * intended, see the end of `bootstrap.php`.
     */
    public function mount($prefix, $controllers);

    /**
     * Replaces an already registered container definition.
     *
     * Called by `ConsoleManager::addCommand()`. **The timing is delicate:** Pimple freezes a
     * definition as soon as it has been read once and throws `FrozenServiceException` after
     * that. `000-000-0006` tripped over exactly this.
     */
    public function extend($id, $callable);

    /** Hook before the action. The priority is intended wherever it is set — see technical.md. */
    public function before($callback, $priority = 0);

    /** Hook after the action. */
    public function after($callback, $priority = 0);
}
