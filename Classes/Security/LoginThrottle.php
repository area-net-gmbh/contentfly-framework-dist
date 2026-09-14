<?php
namespace Areanet\PIM\Classes\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Throttles login attempts — per identifier and per IP, with increasing delay (013-001-0003).
 *
 * WHAT EXISTED BEFORE WAS NO THROTTLE. `AuthController::CHECK_LOGIN_INTERVAL` was a `false`
 * constant, i.e. permanently off. Even switched on it would have been a 60-second gap **per user**,
 * measured from the last issued token — ineffective against guessing across many accounts, and
 * against guessing many passwords for ONE account only if no token was ever created in between. An
 * attacker who never guesses right never issues a token.
 *
 * TWO AXES, BECAUSE EACH ON ITS OWN CAN BE BYPASSED:
 *
 *   per identifier  catches the attack on ONE account, even when it comes from many addresses
 *   per IP          catches trying out MANY identifiers from one address
 *
 * INCREASING DELAY THROUGH STACKED WINDOWS. Instead of one limit there are three on top of each other:
 * one minute, a quarter of an hour, one hour. Whoever breaks the first waits about a minute; whoever
 * carries on waits the quarter of an hour; whoever still carries on, the hour. The waiting time
 * grows with persistence without a counter for "penalty levels" having to be kept anywhere.
 *
 * ONLY FAILED ATTEMPTS COUNT. A successful login consumes nothing and additionally resets the
 * identifier's counter. That is the difference between a throttle and a usage cap: an application
 * that also counts correct logins eventually locks out exactly the users who do everything right.
 *
 * THE IP COUNTER IS NOT RESET. Otherwise a single valid account — the attacker's own — would be
 * enough to unlock themselves after every block.
 *
 * THE CHECK CONSUMES NOTHING. `consume(0)` reads the state without changing it; the reported
 * `isAccepted` is always `true` then, which is why the remaining tokens are counted here and not that
 * flag. If the check itself consumed, every rejected attempt would extend the block — the block would
 * never expire, and an attacker could lock a foreign account permanently by running against the
 * closed door.
 *
 * NO LOCK. `symfony/lock` is not in the tree; two simultaneous failed attempts can therefore share a
 * counting step in the unlucky case. That shifts the limit by single steps, not by orders of
 * magnitude — a lock per login attempt would be an attack surface of its own.
 *
 * THE KEY IS A HASH, and the identifier is lower-cased first: `Admin` and `admin` share one bucket;
 * otherwise the limit could be multiplied through upper and lower case.
 */
final class LoginThrottle
{
    /**
     * The tiers per identifier.
     *
     * Five failed attempts per minute are generous for a person who mistypes and tight for a script.
     */
    private const TIERS_IDENTIFIER = array(
        array('limit' => 5,  'interval' => '1 minute'),
        array('limit' => 20, 'interval' => '15 minutes'),
        array('limit' => 50, 'interval' => '1 hour'),
    );

    /**
     * The tiers per IP, set wider.
     *
     * Many users can sit behind one address — an office with one connection, a mobile gateway. The
     * limit must therefore be above the one a single identifier gets, otherwise it throttles the
     * neighbours instead of the attacker.
     */
    private const TIERS_IP = array(
        array('limit' => 20,  'interval' => '1 minute'),
        array('limit' => 60,  'interval' => '15 minutes'),
        array('limit' => 200, 'interval' => '1 hour'),
    );

    private CacheStorage $storage;

    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->storage = new CacheStorage($pool);
    }

    /**
     * How long this attempt still has to wait — or null if it is let through.
     *
     * The LONGEST waiting time across all broken windows is returned. That is where the increasing
     * delay lies: whoever only breaks the minute limit waits a minute; whoever also breaks the
     * quarter-hour limit waits the quarter of an hour, because that window expires later.
     *
     * THAT IS WHY EVERY WINDOW IS READ ON ITS OWN and not through a `CompoundLimiter`. That one reports
     * the tightest state — after 20 failed attempts that is the minute limit with its -15 tokens, and
     * its waiting time is never longer than a minute. Measured: the second tier thereby ended up with
     * the same 60 seconds as the first, and the tiers had no effect.
     */
    public function retryAfter(?string $identifier, ?string $ip): ?int
    {
        $retryAfter = null;

        foreach ($this->limiters($identifier, $ip) as $limiter) {
            $state = $limiter->consume(0);

            if ($state->getRemainingTokens() >= 1) {
                continue;
            }

            $seconds    = max(1, $state->getRetryAfter()->getTimestamp() - time());
            $retryAfter = max($retryAfter ?? 0, $seconds);
        }

        return $retryAfter;
    }

    /**
     * Counts a failed attempt in every window of both axes.
     */
    public function recordFailure(?string $identifier, ?string $ip): void
    {
        foreach ($this->limiters($identifier, $ip) as $limiter) {
            $limiter->consume(1);
        }
    }

    /**
     * Resets the windows of ONE identifier — after a successful login.
     *
     * The IP axis deliberately stays, see the class comment.
     */
    public function reset(?string $identifier): void
    {
        foreach ($this->limiters($identifier, null) as $limiter) {
            $limiter->reset();
        }
    }

    /**
     * All windows that apply to this attempt — both axes flat in a row.
     *
     * An axis without a value drops out: a login through a login provider brings no identifier, and
     * `getClientIp()` can return null.
     *
     * @return list<LimiterInterface>
     */
    private function limiters(?string $identifier, ?string $ip): array
    {
        $list = array();

        if (($key = $this->key($identifier)) !== null) {
            $list = array_merge($list, $this->tiers('identifier', $key, self::TIERS_IDENTIFIER));
        }

        if (($key = $this->key($ip)) !== null) {
            $list = array_merge($list, $this->tiers('ip', $key, self::TIERS_IP));
        }

        return $list;
    }

    private function key(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return hash('sha256', mb_strtolower($value));
    }

    /**
     * Builds the three stacked windows of one axis.
     *
     * @param  list<array{limit: int, interval: string}> $tiers
     * @return list<LimiterInterface>
     */
    private function tiers(string $axis, string $key, array $tiers): array
    {
        $limiters = array();

        foreach ($tiers as $number => $tier) {
            $factory = new RateLimiterFactory(
                array(
                    'id'       => 'login-'.$axis.'-'.$number,
                    'policy'   => 'sliding_window',
                    'limit'    => $tier['limit'],
                    'interval' => $tier['interval'],
                ),
                $this->storage
            );

            $limiters[] = $factory->create($key);
        }

        return $limiters;
    }
}
