<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tells HttpFoundation which proxies the application sits behind (013-001-0003).
 *
 * UNTIL THEN `setTrustedProxies()` WAS CALLED NOWHERE — not anywhere in the tree. That had no
 * consequences as long as nobody evaluated the caller's IP. With per-IP rate limiting it becomes the
 * core problem: without trusted proxies `Request::getClientIp()` returns the address of the next hop.
 * If a load balancer sits in front, that is ITS address — the throttle would hit it and with it every
 * user behind it, while the attacker keeps guessing unthrottled.
 *
 * WITHOUT CONFIGURATION NOTHING CHANGES. `APP_TRUSTED_PROXIES` is empty by default; then
 * `setTrustedProxies()` is not called at all and the application behaves as before. Whoever
 * configures no proxies runs without them — then the IP is correct anyway.
 *
 * THE DEFAULT IS THE NARROW ONE: only the X-Forwarded-* headers are trusted. A `Forwarded` header per
 * RFC 7239 is only read when `APP_TRUSTED_HEADERS` explicitly says so — it carries the same
 * information, and believing both at once means leaving the caller to choose which one applies.
 *
 * AN UNKNOWN NAME IS REJECTED, not silently mapped back to the default. A typo in
 * `APP_TRUSTED_HEADERS` would otherwise be a configuration that seems to work and does not — the same
 * trap as with the removed cache driver `apc` (010-002-0002).
 */
final class TrustedProxies
{
    /**
     * The allowed values of `APP_TRUSTED_HEADERS`.
     *
     * `x-forwarded` covers the four values a common reverse proxy sets; `forwarded` is the
     * standardised single header from RFC 7239.
     */
    public const HEADER_SETS = array(
        'x-forwarded' => Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        'forwarded'   => Request::HEADER_FORWARDED,
    );

    /**
     * Sets the trusted proxies, if any are configured.
     *
     * @param  array<int|string, string>|string|null $proxies
     * @return bool  true if they were set; false if nothing is configured
     */
    public static function apply($proxies, string $headerSet): bool
    {
        $list = self::list($proxies);

        if ($list === array()) {
            return false;
        }

        Request::setTrustedProxies($list, self::headerSet($headerSet));

        return true;
    }

    /**
     * Turns the value from the configuration into a list.
     *
     * Both forms are allowed: an array of addresses or networks, or a comma-separated string. The
     * second form exists because such a value often comes from an environment variable, where it can
     * only exist as a string.
     *
     * `REMOTE_ADDR` is a special value of HttpFoundation and stays untouched: it means "the immediate
     * sender, whoever that is" and is the right value for an application that is only reachable
     * through a proxy whose address changes.
     *
     * @param  array<int|string, string>|string|null $proxies
     * @return list<string>
     */
    public static function list($proxies): array
    {
        if (is_array($proxies)) {
            $raw = $proxies;
        } elseif (is_string($proxies)) {
            $raw = preg_split('/\s*,\s*/', $proxies, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        } else {
            return array();
        }

        $list = array();
        foreach ($raw as $entry) {
            $entry = trim((string) $entry);
            if ($entry !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Translates `APP_TRUSTED_HEADERS` into HttpFoundation's bit mask.
     */
    public static function headerSet(string $name): int
    {
        $name = strtolower(trim($name));

        if (!isset(self::HEADER_SETS[$name])) {
            throw new \RuntimeException(sprintf(
                'APP_TRUSTED_HEADERS = "%s" is unknown. Allowed values: %s.',
                $name,
                implode(', ', array_keys(self::HEADER_SETS))
            ));
        }

        return self::HEADER_SETS[$name];
    }
}
