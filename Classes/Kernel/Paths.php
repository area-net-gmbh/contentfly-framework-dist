<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * The directories the framework needs — **passed in, not guessed** (007-001-0002).
 *
 * ── What used to be here, and why it had to go ────────────────────────────────────────
 *
 * `lib/contentfly/bootstrap.php`, line 2:
 *
 *     const ROOT_DIR = __DIR__ . '/../..';
 *
 * The framework computed the project directory from ITS OWN LOCATION. That is correct exactly
 * as long as it lives under `lib/contentfly/` inside the project. As soon as it lives as a
 * package under `vendor/areanet/contentfly/lib/contentfly/`, `../..` points to
 * `vendor/areanet/` — and every path built on it points nowhere.
 *
 * **The failure would also have been silent.** `__DIR__ . '/../..'` always yields a path; it
 * just does not exist. A `file_exists()` on it returns `false`, and the resulting message is
 * about a missing file instead of a wrong root. Anyone integrating the package for the first
 * time looks in the wrong place. The same pattern as in `000-000-0029`: an error that disguises
 * itself as something else.
 *
 * That is why every accessor here throws as long as nothing has been set — and the message
 * says WHAT is missing and WHO has to set it.
 *
 * ── Two directories, not one ──────────────────────────────────────────────────────────
 *
 * `project()` is the project directory: that is where `custom/`, `data/` and `plugins/` live.
 * The value comes from the entry point and cannot be derived.
 *
 * `package()` is the directory of the framework itself. It MAY come from `__DIR__` — a file may
 * find its own package; it just must not conclude from that where the project lives. Exactly
 * this distinction was missing before, which is why both used to be the same constant.
 *
 * ── Why static and not through the container ─────────────────────────────────────────
 *
 * Considered: the paths as services in `Classes\Kernel\Container`, passed in everywhere.
 * Rejected for this task — the call sites (`Classes\Api`, `Classes\Plugin`,
 * `Classes\File\Backend\FileSystem`, `Command\InstallCommand`) have no container at hand at the
 * affected lines, and giving them one would be a second refactoring inside this one. The static
 * access mirrors `Config\Adapter::getConfig()`, which already plays the same role.
 *
 * The price is named: global state. It is made bearable by being set **once** at startup and by
 * every access before that throwing instead of returning a wrong value.
 */
final class Paths
{
    private static ?string $project = null;

    /**
     * Sets the project directory. Once, at startup, from the entry point.
     *
     * The directory is checked to exist — otherwise the error would only surface on the first
     * access to a file below it, and the message would again be about that file.
     */
    public static function set(string $project): void
    {
        $resolved = realpath($project);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \LogicException(sprintf(
                'The project directory "%s" does not exist. The entry point (index.php, '
                .'bin/console.php) passes it to Areanet\PIM\Classes\Kernel\Paths::set(); '
                .'a checked value instead of a guessed path — see 007-001-0002.',
                $project
            ));
        }

        self::$project = $resolved;
    }

    public static function isSet(): bool
    {
        return self::$project !== null;
    }

    /**
     * For tests only: forget the value that was set.
     *
     * Without this method no test could check that an access without `set()` throws — and
     * that is exactly the guarantee of this class.
     */
    public static function reset(): void
    {
        self::$project = null;
    }

    public static function project(): string
    {
        if (self::$project === null) {
            throw new \LogicException(
                'The project directory has not been set. The entry point must pass it to the '
                .'framework before anything accesses it: '
                .'Areanet\PIM\Classes\Kernel\Paths::set(__DIR__). '
                .'It used to be computed from the location of the framework (ROOT_DIR) — which '
                .'silently went wrong as soon as the framework lived elsewhere (007-001-0002).'
            );
        }

        return self::$project;
    }

    /** Project code and project configuration. */
    public static function custom(): string
    {
        return self::project() . '/custom';
    }

    /** Writable runtime directories: `cache`, `files`, `import`, `temp`. */
    public static function data(): string
    {
        return self::project() . '/data';
    }

    /** The project's plugin slot. */
    public static function plugins(): string
    {
        return self::project() . '/plugins';
    }

    /**
     * The directory of the framework itself — the root of the package `areanet/contentfly`.
     *
     * Derived from `__DIR__`, and that is correct here: this file lives in
     * `<package>/Classes/Kernel/`, i.e. two levels below the package root. A file may find its
     * own package — it just must not conclude from that where the project lives.
     *
     * **The depth changed with `007-001-0004`, and so did the reference point.** Before, it was
     * four levels and the result was the root of the repository, because `lib/contentfly/` lived
     * inside it. Now `lib/contentfly/` itself is the package: it carries its own
     * `composer.json`, and Composer places it under `vendor/areanet/contentfly/`. Two levels up
     * from there is the same as four levels from here used to be — the difference is that it no
     * longer depends on how deep the package sits inside the project.
     */
    public static function package(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The entity directories Doctrine reads its mapping from. */
    public static function frameworkEntities(): string
    {
        return self::package() . '/Entity';
    }

    public static function projectEntities(): string
    {
        return self::custom() . '/Entity';
    }
}
