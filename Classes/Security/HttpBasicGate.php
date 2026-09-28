<?php
namespace Areanet\PIM\Classes\Security;

/**
 * THE OPTIONAL HTTP BASIC GATE — AND WHY IT WAS NOT ONE (015-000-0007).
 *
 * `APP_HTTP_AUTH_USER` / `APP_HTTP_AUTH_PASS` put a second door in front of the whole
 * application, for a staging environment that is not meant to be public. Behind it sit
 * `/auth/login`, `/auth/refresh`, `/api/config`, `/file/get/*` and every custom route with
 * `isSecure = false`.
 *
 * The check in `bootstrap-web.php` read:
 *
 *     if ($_SERVER['PHP_AUTH_USER'] != $expectedUser && $_SERVER['PHP_AUTH_PW'] != $expectedPass)
 *
 * It refused only when user **and** password were wrong. One correct value was enough:
 * `Authorization: Basic base64("staging:anything")` walked past a gate whose user is `staging`,
 * and so did `base64("anyone:the-real-password")`. The comparison was loose (`!=`) and not
 * constant-time, and the header was taken apart with `explode(':')` without checking that there
 * was a colon at all.
 *
 * ── Why a class and not three fixed lines ──────────────────────────────────────────────
 *
 * `bootstrap-web.php` is a script that runs on every request before anything else exists. A rule
 * that lives there cannot be measured, and this one had been wrong for as long as it existed
 * without anybody noticing. Here it is two pure functions, and `HttpBasicGateTest` walks the
 * cases that matter — including the two that used to pass.
 *
 * ── Fail closed ────────────────────────────────────────────────────────────────────────
 *
 * A gate configured with a user but no password lets nobody through. That is a change of
 * behaviour and a deliberate one: before, such a configuration admitted anybody who knew the
 * user name, which is the same finding once more. The register entry says so, because it locks
 * out an installation that upgrades with half a configuration.
 */
final class HttpBasicGate
{
    /**
     * The user and password out of an `Authorization` header.
     *
     * Returns `[null, null]` for anything that is not a complete Basic credential pair — no
     * header, another scheme, undecodable base64, or a payload without a colon. A payload
     * without a colon is not a pair with an empty password; it is not a pair at all, and
     * `explode(':')` on it used to yield a single element that `list()` read as `null`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function credentials(?string $header): array
    {
        $none = array(null, null);

        if ($header === null || stripos($header, 'Basic ') !== 0) {
            return $none;
        }

        // Strict mode: a payload with characters outside the alphabet is not a credential pair.
        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return $none;
        }

        // Limit 2: a password may contain colons, a user name may not.
        [$user, $password] = explode(':', $decoded, 2);

        return array($user, $password);
    }

    /**
     * Do these credentials open the gate?
     *
     * Both values are compared, both with `hash_equals()`, and the results are combined only
     * afterwards — `&&` on two already computed booleans, so the second comparison is not
     * skipped when the first fails.
     *
     * The expected values are parameters rather than read from the configuration: that is what
     * makes the rule measurable from both sides, and the half-configured case below is one no
     * installation of this suite runs.
     */
    public static function isAuthorised(
        ?string $user,
        ?string $password,
        ?string $expectedUser,
        ?string $expectedPassword
    ): bool {
        // Half a configuration is not a gate — see "Fail closed" above.
        if ((string) $expectedUser === '' || (string) $expectedPassword === '') {
            return false;
        }

        // An empty half is not a credential, whatever the configuration says.
        if ((string) $user === '' || (string) $password === '') {
            return false;
        }

        $userMatches     = hash_equals((string) $expectedUser, (string) $user);
        $passwordMatches = hash_equals((string) $expectedPassword, (string) $password);

        return $userMatches && $passwordMatches;
    }
}
