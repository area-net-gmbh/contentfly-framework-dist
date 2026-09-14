<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AccessTokenAuthenticator;

/**
 * Drives Symfony's `access_token` authenticator — without a firewall (013-002-0001).
 *
 * THE FIREWALL YAML FROM THE STORY SCOPE DOES NOT EXIST IN THIS TREE. No `config/` directory, no
 * SecurityBundle; the kernel is the framework's own from epic `009`, with `before()` hooks per
 * provider. That is not an obstacle, just a different route to the same result:
 * `AccessTokenAuthenticator` is an ordinary class that takes a handler, an extractor and optionally
 * a user loader.
 *
 * THREE CALLS ARE THE WHOLE MECHANISM:
 *
 *   `supports()`      asks the extractor whether the request carries a token at all
 *   `authenticate()`  lets the handler build a `UserBadge` and wraps it in a passport
 *   `getUser()`       resolves the badge — through the badge's own loader or the user loader
 *
 * The machinery around it — `AuthenticatorManager`, `FirewallMap`, `TokenStorage`, the
 * `kernel.request` listeners — stays outside. It carries state across sessions and multiple
 * firewalls; Contentfly has neither.
 *
 * EVERY FAILURE LOOKS THE SAME. `AuthenticationException`, the common parent class, is caught — no
 * token, unknown identifier, deactivated user, expired or tampered token all end in `null`. Whoever
 * distinguishes causes here tells the caller which kind of token is expected and which accounts
 * exist.
 *
 * WIRED UP SINCE 013-002-0004: `BaseControllerProvider::authenticate()` calls it, and `checkToken()` no
 * longer exists.
 */
final class TokenAuthenticator
{
    private AccessTokenAuthenticator $authenticator;

    public function __construct(
        AccessTokenHandlerInterface $handler,
        AccessTokenExtractorInterface $extractor,
        ?UserProviderInterface $userLoader = null,
    ) {
        $this->authenticator = new AccessTokenAuthenticator($handler, $extractor, $userLoader);
    }

    /**
     * The user behind this request's token — or null.
     */
    public function user(Request $request): ?UserInterface
    {
        /*
         * `=== false` and not `!`: `supports()` returns **null** when a token is present — the
         * marker for "maybe, decide later". Only `false` means "no token at all". A
         * `!$this->authenticator->supports(...)` would treat both cases alike and thereby reject
         * every request.
         */
        if ($this->authenticator->supports($request) === false) {
            return null;
        }

        try {
            return $this->authenticator->authenticate($request)->getUser();
        } catch (AuthenticationException) {
            return null;
        }
    }
}
