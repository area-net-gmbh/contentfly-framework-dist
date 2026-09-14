<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * The door into the framework (007-001-0003).
 *
 * ── What is turned around here ────────────────────────────────────────────────────────
 *
 * The entry point used to be one line:
 *
 *     require_once __DIR__.'/lib/contentfly/bootstrap-web.php';
 *
 * And the bootstrap then loaded whatever it needed to run by itself — the autoloader, the
 * second autoloader, the project configuration. **A package is loaded by the autoloader; it
 * does not load it.** As long as `bootstrap.php` is the first file anyone includes, the
 * framework code cannot live in `vendor/` at all: to find it you would need the autoloader it
 * only loads itself.
 *
 * Now the entry point loads the autoloader and calls a class:
 *
 *     require_once __DIR__.'/vendor/autoload.php';
 *     \Areanet\PIM\Classes\Kernel\Start::web(__DIR__);
 *
 * **As a result, no entry point contains a path into the framework code any more.** That is the
 * actual gain: whether `lib/contentfly/` lives inside the project or under
 * `vendor/areanet/contentfly/`, the entry point can no longer tell.
 *
 * ── What is checked here, and why exactly here ────────────────────────────────────────
 *
 * Three conditions that used to be either not checked at all or only noticed much later. They
 * live here and not in the bootstrap because both paths — web and console — go through this
 * class, and a check kept in two places drifts apart.
 */
final class Start
{
    /**
     * The web entry. Does not return — the bootstrap ends with `$app->run()`.
     */
    public static function web(string $project): void
    {
        self::prepare($project);

        require Paths::package() . '/bootstrap-web.php';
    }

    /**
     * The console entry. Returns the assembled application.
     *
     * `APPCMS_CONSOLE` controls in several places that no redirect and no session happen; the
     * constant is defined here so that a caller cannot forget it.
     */
    public static function console(string $project): ApplicationInterface
    {
        if (!defined('APPCMS_CONSOLE')) {
            define('APPCMS_CONSOLE', true);
        }

        self::prepare($project);

        /** @var ApplicationInterface $app */
        require Paths::package() . '/bootstrap.php';

        return $app;
    }

    /**
     * Everything that has to be settled before the bootstrap.
     */
    private static function prepare(string $project): void
    {
        Paths::set($project);

        /*
         * For custom/config.php, which locates the .env file relative to it. The constant is
         * what the PROJECT still sees of ROOT_DIR — the framework itself no longer uses it, it
         * asks Paths.
         */
        if (!defined('CONTENTFLY_PROJECT_DIR')) {
            define('CONTENTFLY_PROJECT_DIR', Paths::project());
        }

        self::assertNoSecondVendorTree();
        self::assertConfigurationExists();
    }

    /**
     * One project, one Composer tree (007-001-0001).
     *
     * Up to this point `custom/vendor/autoload.php` was loaded in addition, and the precedence
     * "root beats project" was a guarantee from `006-004-0001`. With the library package its
     * basis disappears: the framework is then a dependency **inside** the project's tree; there
     * are no longer two trees whose precedence would need to be settled.
     *
     * **Why this aborts instead of silently ignoring it.** A leftover `custom/vendor/` looks
     * like something that is in use. If it simply stopped being loaded, the project would miss a
     * class, and the message would be about that class — not about a whole tree no longer being
     * valid. The same pattern that `000-000-0029` and `007-001-0002` are built against.
     */
    private static function assertNoSecondVendorTree(): void
    {
        $second = Paths::custom() . '/vendor/autoload.php';

        if (!is_file($second)) {
            return;
        }

        self::abort(
            "There is still a second Composer tree under custom/vendor/.\n\n"
            ."Since 007-001 a project has exactly ONE tree: the framework is a dependency\n"
            ."inside it, not a second tree next to it. Whatever lives in custom/vendor/ is\n"
            ."no longer loaded — and accepting that silently would be worse than aborting:\n"
            ."the follow-up message would be about a missing class instead of a tree that\n"
            ."no longer applies.\n\n"
            ."To do: move whatever custom/composer.json still needs into the project's\n"
            ."manifest, then remove custom/vendor/ and custom/composer.json.\n"
            ."See an_project/docs/breaking-changes.md."
        );
    }

    /**
     * The project configuration — looked for in exactly one place, and that place is named.
     *
     * It used to be a bare `require_once` on a fixed path. If the file was missing, PHP's own
     * message appeared: "Failed opening required …". It names the path, but not that this is the
     * configuration and how to get it.
     */
    private static function assertConfigurationExists(): void
    {
        $configuration = Paths::custom() . '/config.php';

        if (is_file($configuration) && is_readable($configuration)) {
            return;
        }

        self::abort(sprintf(
            "The project configuration is missing or not readable:\n\n    %s\n\n"
            ."Contentfly expects it at custom/config.php in the project directory.\n"
            ."The project directory is: %s\n\n"
            ."If that is wrong, the entry point passes the wrong directory to\n"
            ."Start::web() or Start::console().\n\n"
            ."On a fresh checkout, `php bin/console.php appcms:install` creates it.",
            $configuration,
            Paths::project()
        ));
    }

    /**
     * Abort with a message that arrives in both worlds.
     *
     * **`STDERR` only exists in the CLI.** In the web SAPI the constant is not defined, and a
     * `fwrite(STDERR, …)` would itself be an error there — the message about the error would
     * cause a second one and get lost. (Exactly that was in `bootstrap.php` after `007-001-0002`
     * and is corrected with this task.)
     *
     * Thrown instead of `exit`: an exception carries the message into the error log the
     * environment keeps anyway, and it can be checked in a test. An `exit` could not be checked —
     * and an abort path no test ever enters is an abort path nobody can rely on.
     */
    private static function abort(string $message): never
    {
        throw new \RuntimeException("Contentfly cannot start.\n\n" . $message);
    }
}
