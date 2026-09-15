<?php
namespace Areanet\PIM\Classes\ORM;

use Doctrine\ORM\Proxy\ProxyFactory;

/**
 * Turns `APP_AUTOGENERATE_PROXIES` into Doctrine's proxy generation mode (000-000-0047).
 *
 * THE DEFAULT WAS "REGENERATE ON EVERY REQUEST". The bootstrap passed `(bool)` of the setting, whose
 * default is `true` — and `true` is `ProxyFactory::AUTOGENERATE_ALWAYS`. Every request that touched a
 * lazy association wrote the proxy files again. Measured on the existing project UFP (007-005-0004):
 * ten list and evaluation calls took 540 ms against 382 ms on Contentfly 1.x, and 330 ms without the
 * regeneration. Concurrent requests also wrote the same file at the same time; on a bind-mounted
 * `data/` that surfaced as `require(...__CG__...User.php): Operation not permitted`.
 *
 * The `(bool)` also made every other mode unreachable: a project could only choose "always" or "never".
 *
 * The new default is `AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED`: a proxy is written when it is missing or
 * its entity file changed. That keeps development working without a console step and costs production
 * one `file_exists()` per proxy class per request.
 *
 * `true` and `false` keep their old meaning, so a project that set the value explicitly behaves as
 * before. Doctrine's constants (0 to 4) are passed through; anything else is a configuration error and
 * says so, instead of being cast to one of two modes.
 */
final class ProxyGeneration
{
    public const DEFAULT = ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED;

    public static function mode(mixed $configured): int
    {
        if ($configured === true) {
            return ProxyFactory::AUTOGENERATE_ALWAYS;
        }

        if ($configured === false) {
            return ProxyFactory::AUTOGENERATE_NEVER;
        }

        $valid = array(
            ProxyFactory::AUTOGENERATE_NEVER,
            ProxyFactory::AUTOGENERATE_ALWAYS,
            ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
            ProxyFactory::AUTOGENERATE_EVAL,
            ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED,
        );

        if (is_int($configured) && in_array($configured, $valid, true)) {
            return $configured;
        }

        throw new \InvalidArgumentException(sprintf(
            'APP_AUTOGENERATE_PROXIES must be true, false or one of the Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_* constants, got %s.',
            var_export($configured, true)
        ));
    }
}
