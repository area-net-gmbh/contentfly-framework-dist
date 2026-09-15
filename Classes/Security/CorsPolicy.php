<?php
namespace Areanet\PIM\Classes\Security;

/**
 * Which origin a cross-origin response may name (000-000-0039).
 *
 * ── Why ────────────────────────────────────────────────────────────────────────────────
 *
 * The after hook in `bootstrap-web.php` used to copy the request's `Origin` into
 * `Access-Control-Allow-Origin` and send `Access-Control-Allow-Credentials: true` along with it.
 * Measured on 2026-09-15: `Origin: https://evil.example` came back as the allowed origin. Every
 * foreign page could then make requests with a signed-in user's credentials and read the answer.
 * `Config::$APP_ALLOW_ORIGIN` existed the whole time and was never read.
 *
 * Found at the existing project UFP (007-005-0001), which sets the origin from its configuration in
 * its own copy of the framework.
 *
 * ── The rule ───────────────────────────────────────────────────────────────────────────
 *
 * DECIDED ON 2026-09-15: only a configured origin is named, and only exactly that one. Nothing
 * configured means no foreign origin at all — a browser client on another origin (an Ionic app on
 * `capacitor://localhost`, say) has to be listed. `*` stays possible as an explicit choice for a
 * public API; browsers refuse credentials with it, so none are offered.
 */
final class CorsPolicy
{
    /**
     * The value for `Access-Control-Allow-Origin`, or null when the header must not be sent.
     *
     * @param mixed $configured `APP_ALLOW_ORIGIN`: null, a string (one origin or a comma-separated
     *                          list) or an array of origins
     */
    public static function allowedOrigin(?string $origin, mixed $configured): ?string
    {
        $allowed = self::normalize($configured);

        if (in_array('*', $allowed, true)) {
            return '*';
        }

        if ($origin === null || $origin === '') {
            return null;
        }

        $origin = rtrim($origin, '/');

        foreach ($allowed as $entry) {
            // Scheme and host are case-insensitive; a browser sends them in lower case anyway.
            if (strcasecmp($entry, $origin) === 0) {
                return $origin;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function normalize(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (!is_array($configured)) {
            return array();
        }

        return array_values(array_filter(array_map(
            static fn ($entry) => rtrim(trim((string) $entry), '/'),
            $configured
        ), static fn (string $entry) => $entry !== ''));
    }
}
