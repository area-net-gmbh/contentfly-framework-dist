<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Issues access JWTs and defines what they contain (013-003-0001).
 *
 * THE CLAIM SET LIVES HERE AND ONLY HERE. Until this task the `TokenHandler` could verify JWTs, but
 * nobody issued any — so there was no set to verify against either. Five claims, nothing more:
 *
 *   sub   the user's identifier
 *   iss   the issuer, so that a token of a foreign application is not valid here
 *   iat   when it was issued
 *   exp   when it expires
 *   jti   the ID of this one token, for revocation in 013-003-0003
 *
 * WHAT IS DELIBERATELY NOT IN IT: roles, groups, permissions. They can change while the token is
 * valid — if they were in the token, a change of permissions would only take effect after it
 * expires. That is the classic mistake when moving to stateless tokens, and it only shows up when
 * someone's permission is revoked and it does not take effect.
 *
 * The user is therefore loaded from `pim_user` on every request (see `UserLoader`). That is one
 * query — the one on `pim_token`, including a write on every call, goes away in exchange.
 *
 * BEING SHORT-LIVED IS THE ENTIRE SECURITY FEATURE. A stateless token cannot be recalled while it is
 * valid; the shorter it is valid, the smaller the window. It is renewed through the refresh token
 * (`013-003-0002`).
 */
final class JwtAccessToken
{
    /**
     * The issuer.
     *
     * A constant and not a configuration field: the value should be the same across all
     * installations because it names the application, not the instance. Whoever wants to separate two
     * instances gives them different secrets — that separates effectively; a different `iss` with the
     * same secret does not.
     */
    public const ISSUER = 'contentfly';

    /** The algorithm. A token with a different `alg` is rejected. */
    public const ALGORITHM = 'HS256';

    /**
     * The claims an issued token carries — and that are checked.
     *
     * A list so that a test can hold it against a real token and notice when someone adds a sixth.
     */
    public const CLAIMS = array('sub', 'iss', 'iat', 'exp', 'jti');

    /**
     * Issues an access JWT.
     *
     * @return array{token: string, jti: string, exp: int}
     */
    public static function issue(UserInterface $user, ?int $ttl = null): array
    {
        $secret = self::secret();
        $now    = time();
        $exp    = $now + ($ttl ?? self::ttl());
        $jti    = bin2hex(random_bytes(16));

        $claims = array(
            'sub' => $user->getUserIdentifier(),
            'iss' => self::ISSUER,
            'iat' => $now,
            'exp' => $exp,
            'jti' => $jti,
        );

        return array(
            // The fourth parameter sets `kid` in the header — the handle for key rotation
            // (013-003-0004).
            'token' => JWT::encode($claims, $secret, self::ALGORITHM, self::keyId()),
            'jti'   => $jti,
            'exp'   => $exp,
        );
    }

    /**
     * Whether this installation can issue JWTs at all.
     *
     * Separate from `secret()` so that the caller can give an understandable answer instead of letting
     * an exception through.
     */
    public static function isConfigured(): bool
    {
        $secret = Adapter::getConfig()->SECURITY_JWT_SECRET;

        return is_string($secret) && $secret !== '';
    }

    /**
     * The signing secret.
     *
     * WITHOUT A VALUE IT THROWS instead of carrying on with a substitute. A default key stored in the
     * code would be no key; and an application that silently does something other than what was asked
     * is worse than one that stops.
     */
    public static function secret(): string
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'SECURITY_JWT_SECRET is not set. Without a signing secret no JWTs can be issued; '
                .'see custom/config.php.'
            );
        }

        return (string) Adapter::getConfig()->SECURITY_JWT_SECRET;
    }

    /** The ID of the current key — it appears as `kid` in the token header. */
    public static function keyId(): string
    {
        $value = (string) Adapter::getConfig()->SECURITY_JWT_KEY_ID;

        return $value !== '' ? $value : 'k1';
    }

    /**
     * All keys tokens are verified against — by key ID.
     *
     * THE CURRENT ONE AND, IF SET, THE PREVIOUS ONE. That is the whole key rotation: tokens are signed
     * with the current key, both are accepted. Whoever rotates moves the current value to
     * `SECURITY_JWT_SECRET_PREVIOUS` and creates a new one — nobody has to log in again, and after the
     * longest access JWT has expired the old one can go.
     *
     * TWO IDENTICAL KEY IDS ARE REJECTED. Otherwise one would overwrite the other in the array, and the
     * application would silently accept only one of the two keys — in the middle of a rotation the
     * worst moment for a silent surprise.
     *
     * @return array<string, Key>
     */
    public static function verificationKeys(): array
    {
        $keys = array(self::keyId() => new Key(self::secret(), self::ALGORITHM));

        $previous      = Adapter::getConfig()->SECURITY_JWT_SECRET_PREVIOUS;
        $previousKeyId = Adapter::getConfig()->SECURITY_JWT_KEY_ID_PREVIOUS;

        if (!is_string($previous) || $previous === '') {
            return $keys;
        }

        if (!is_string($previousKeyId) || $previousKeyId === '') {
            throw new \RuntimeException(
                'SECURITY_JWT_SECRET_PREVIOUS is set, SECURITY_JWT_KEY_ID_PREVIOUS is not. '
                .'A key without an ID cannot be matched to any token.'
            );
        }

        if ($previousKeyId === self::keyId()) {
            throw new \RuntimeException(
                'SECURITY_JWT_KEY_ID and SECURITY_JWT_KEY_ID_PREVIOUS are identical ("'
                .$previousKeyId.'"). During a key rotation they must differ, otherwise only one '
                .'of the two keys is valid.'
            );
        }

        $keys[$previousKeyId] = new Key($previous, self::ALGORITHM);

        return $keys;
    }

    /** The lifetime in seconds, from the configuration. */
    public static function ttl(): int
    {
        $value = (int) Adapter::getConfig()->SECURITY_JWT_TTL;

        return $value > 0 ? $value : 900;
    }
}
