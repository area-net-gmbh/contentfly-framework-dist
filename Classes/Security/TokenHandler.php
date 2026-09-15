<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\RevokedToken;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Branches by the shape of the token (013-002-0003).
 *
 * SYMFONY ALLOWS EXACTLY ONE `token_handler` PER FIREWALL and brings no chaining. You write the
 * branching yourself — and that is the core of this story: "stateful or stateless" is no longer a
 * per-endpoint decision but a property of the issued token.
 *
 *   three dot-separated segments + readable JOSE header  ->  JWT branch
 *   everything else                                       ->  opaque branch
 *
 * THE HEADER IS CHECKED AS WELL, not just the two dots. An opaque token that a project chose itself
 * through `addToken` may contain dots — `pim_token.token` accepts any string. If the shape alone
 * decided, such a token would land in the wrong branch and be rejected although it is in the
 * database.
 *
 * BOTH BRANCHES FAIL INDISTINGUISHABLY. Every failure — unknown token, deactivated user, expired,
 * wrong signature, no secret set — throws the same exception with the same message. Different
 * messages reveal which kind of token is expected, and thereby which kind an attacker has to build.
 *
 * THIS VERSION VERIFIES JWTS, IT DOES NOT ISSUE THEM. Issuing, the refresh model, revocation and key
 * rotation are `013-003`. A secret from the environment is enough here.
 */
final class TokenHandler implements AccessTokenHandlerInterface
{
    /**
     * The one message for every failure.
     *
     * It is a constant so that nobody accidentally introduces a second one: the test holding both
     * branches against each other would report it — but a constant makes the intent visible while
     * writing.
     */
    public const REJECTION = 'Invalid token.';

    /**
     * The opaque token resolved last — or null.
     *
     * The caller needs it: `$app['auth.token']` carries the token entity, and the permission model
     * reads it. Looking it up a second time would be a second query for a row that was just in hand.
     *
     * A handler lives for one request — the container creates it per request. In the JWT branch the
     * field stays null, because there is no row there.
     */
    private ?Token $lastToken = null;

    /**
     * The claims of the access JWT verified last — or null.
     *
     * Logout needs `jti` and `exp` to put the token on the revocation list (013-003-0003). Reading
     * them from the token a second time would mean verifying the signature a second time — the same
     * work for the same result.
     *
     * @var array<string, mixed>|null
     */
    private ?array $lastClaims = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $this->lastToken  = null;
        $this->lastClaims = null;

        return $this->looksLikeJwt($accessToken)
            ? $this->fromJwt($accessToken)
            : $this->fromDatabase($accessToken);
    }

    public function lastToken(): ?Token
    {
        return $this->lastToken;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastClaims(): ?array
    {
        return $this->lastClaims;
    }

    // ── The branching ──────────────────────────────────────────────────────────────────

    /**
     * Three dot-separated segments, the first of which is a readable JOSE header.
     *
     * The only check is whether the header can be read as JSON with `alg` — not whether the algorithm
     * is acceptable. The JWT branch answers that question, with a rejection if the answer is wrong.
     * Here the only concern is which branch the token belongs to.
     */
    private function looksLikeJwt(string $token): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);

        if ($raw === false) {
            return false;
        }

        $header = json_decode($raw, true);

        return is_array($header) && isset($header['alg']);
    }

    // ── The JWT branch ─────────────────────────────────────────────────────────────────

    /**
     * Signature and claims — and no query on `pim_token`.
     *
     * That is the whole gain of this branch: the sliding-expiration write the opaque branch performs
     * on EVERY request does not happen here. `pim_user` is still read — without a user there is no
     * `UserBadge`, and the `UserLoader` takes care of that because this badge deliberately comes back
     * WITHOUT its own loader.
     */
    private function fromJwt(string $token): UserBadge
    {
        /*
         * WITHOUT A SECRET IT IS REJECTED, not skipped.
         *
         * A branch that switches itself off for lack of configuration is no check. The rejection
         * looks like any other — that a secret is missing here is the operator's concern and nothing
         * the caller needs to learn.
         */
        if (!JwtAccessToken::isConfigured()) {
            $this->reject();
        }

        /*
         * THE KEYS ARE FETCHED OUTSIDE THE try (013-003-0004).
         *
         * `verificationKeys()` only throws on a MISCONFIGURATION — two identical key IDs, or a
         * previous key without an ID. That is not an invalid token, and it must not look like one: if
         * it were caught here, the application would answer every request with "invalid token", and
         * the operator would look for the mistake in their clients. This way it fails loudly, with a
         * message naming the fields.
         */
        $keys = JwtAccessToken::verificationKeys();

        try {
            /*
             * An array instead of a single key: `JWT::decode()` then chooses by the `kid` in the
             * header. A TOKEN WITHOUT `kid` IS THEREFORE REJECTED, and that is the decision: without an
             * ID the application would have to guess which key is meant — and "try all of them in
             * turn" defeats the purpose of rotation, because a retired key would then keep vouching for
             * tokens that say nothing about themselves. A token without `kid` was never issued:
             * issuing arrived with 013-003-0001, the key ID with 013-003-0004, and there was no release
             * in between.
             */
            $claims = JWT::decode($token, $keys);
        } catch (\Throwable) {
            $this->reject();
        }

        /*
         * THE ISSUER IS CHECKED (013-003-0001).
         *
         * The library checks signature and expiry, not `iss`. Without this line every token signed
         * with the same secret would be valid here — including one that an entirely different
         * application issued for an entirely different purpose. Shared secrets are a bad idea, but
         * they happen, and then this application should not be the weakest link.
         */
        if (($claims->iss ?? null) !== JwtAccessToken::ISSUER) {
            $this->reject();
        }

        $identifier = $claims->sub ?? null;

        if (!is_string($identifier) || $identifier === '') {
            $this->reject();
        }

        $jti = $claims->jti ?? null;

        if (!is_string($jti) || $jti === '') {
            $this->reject();
        }

        /*
         * THE REVOCATION LIST (013-003-0003).
         *
         * ONE READ, AND IT IS THE PRICE OF REVOCATION. Without it a logged-out token would stay valid
         * until its `exp` — for a token someone has intercepted, that is exactly the damage.
         *
         * WHAT THE JWT BRANCH STILL DOES NOT DO: touch `pim_token`. The sliding-expiration write on
         * EVERY request, the reason for the whole rework, stays gone. What stands here is a read on a
         * small table with a unique index instead of a write on the token table.
         */
        $revoked = $this->em->getRepository(RevokedToken::class)->findOneBy(array('jti' => $jti));

        if ($revoked instanceof RevokedToken) {
            $this->reject();
        }

        $this->lastClaims = (array) $claims;

        // Without its own loader: the user is fetched by the UserLoader the authenticator knows.
        return new UserBadge($identifier);
    }

    // ── The opaque branch ──────────────────────────────────────────────────────────────

    /**
     * What `BaseControllerProvider::checkToken()` always did — adopted unchanged.
     *
     * The SHA-256 of the presented token is looked up (`013-001-0004`), not the token itself. The
     * timeout comes from the user's group, otherwise from `APP_TOKEN_TIMEOUT`; a token with a
     * `referrer` is an API token and does not expire. An expired one is deleted, a valid one is
     * written back to `modified` — the sliding-expiration write.
     */
    private function fromDatabase(string $token): UserBadge
    {
        /*
         * NO THROTTLE WHEN A TOKEN IS PRESENTED — decided, not inherited (000-000-0030).
         *
         * The `LoginThrottle` from 013-001-0003 guards the login, not this path. For an opaque
         * token that is deliberate:
         *
         *   - Guessing online is out of reach for what `addToken` now accepts: a generated value
         *     has 512 bits, a supplied one clears a floor of 32 characters. A throttle protects a
         *     space that can be searched; this one cannot be, within any request budget.
         *   - A throttle keyed on the IP would sit in front of every API request. Behind a shared
         *     proxy or NAT, a misconfigured client would lock out every other client of the same
         *     address — an availability risk traded for a guessing risk that no longer exists.
         *   - A failed lookup costs one indexed query on the hash, the same as a successful one.
         *
         * What the floor does not cover — a value chosen weak on purpose that still passes — no
         * throttle would cover either: it is reversed offline from a table dump, not guessed here.
         */

        $row = $this->em->getRepository(Token::class)->findOneBy(
            array('token' => Token::hash($token))
        );

        if (!$row instanceof Token) {
            $this->reject();
        }

        /*
         * A REFRESH TOKEN IS NOT AN ACCESS TOKEN (013-003-0001).
         *
         * It is an ordinary row in `pim_token` — and until now this branch accepted every row. A
         * refresh token lives longer than an access JWT, that is its purpose; without this check it
         * would be a long-lived master key for the whole API, exactly what the refresh model is meant
         * to prevent.
         *
         * It is rejected like everything else: whoever presents a refresh token at the wrong door does
         * not learn that it would fit another one.
         */
        if ($row->isRefreshToken()) {
            $this->reject();
        }

        $user = $row->getUser();

        if (!$user || !$user->getIsActive()) {
            $this->reject();
        }

        if (self::isExpired($row, $user)) {
            $this->em->remove($row);
            $this->em->flush();

            $this->reject();
        }

        if (self::timeoutApplies($row)) {
            $row->setModified(new \DateTime());
            $this->em->flush();
        }

        $this->lastToken = $row;

        // WITH its own loader: the user is already at hand. Fetching it again through the UserLoader
        // would be a second query for the same row.
        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }

    // ── Expiry, in one place ───────────────────────────────────────────────────────────

    /**
     * Whether a timeout applies to this row at all.
     *
     * A token with a `referrer` is an API token and does not expire; and the operator can switch the
     * check off entirely. Both have always been this way.
     *
     * ── No expiry for API tokens — decided, not inherited (000-000-0030) ──────────────────
     *
     * Finding A-5 named "never expires" as a weakness. An expiry was considered and rejected:
     *
     *   - An API token sits in the configuration of another system. A deadline would stop that
     *     integration on a date nobody watches — the failure would be an outage, not a message.
     *   - An expiry is not this timeout. The sliding expiration here is about inactivity; an
     *     optional deadline set at creation would be a second mechanism, with a column and a check
     *     of its own, for a case nobody has asked for.
     *   - Guessing is no longer the risk: `addToken` generates the value or requires a floor. What
     *     remains is a leaked token, and for that there is revocation — `deleteToken` works since
     *     000-000-0015 and takes effect immediately, a deadline only eventually.
     */
    public static function timeoutApplies(Token $row): bool
    {
        return (bool) Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT && !$row->getReferrer();
    }

    /**
     * The lifetime of a token row in seconds.
     *
     * The user's group beats the default — and it counts in MINUTES. That is a legacy, not a thing of
     * beauty, but it is the old behaviour.
     */
    public static function timeoutFor(User $user): int
    {
        if (($group = $user->getGroup())) {
            return (int) $group->getTokenTimeout() * 60;
        }

        return (int) Adapter::getConfig()->APP_TOKEN_TIMEOUT;
    }

    /**
     * Whether this row has expired.
     *
     * EXTRACTED WITH 013-003-0002: the refresh path needs the same calculation. Two copies of the same
     * expiry logic drift apart, and the one that then gets it wrong lets someone in longer than
     * intended.
     */
    public static function isExpired(Token $row, User $user): bool
    {
        if (!self::timeoutApplies($row)) {
            return false;
        }

        $timeout = self::timeoutFor($user);

        if (!$timeout) {
            return false;
        }

        return (time() - $row->getModified()->getTimestamp()) > $timeout;
    }

    private function reject(): never
    {
        throw new CustomUserMessageAuthenticationException(self::REJECTION);
    }
}
