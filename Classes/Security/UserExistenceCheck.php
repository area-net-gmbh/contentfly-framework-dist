<?php
namespace Areanet\PIM\Classes\Security;

/**
 * Can this provider tell whether an identifier still exists? (013-005-0002)
 *
 * AN ADDITIONAL CAPABILITY, NOT A DUTY. `LoginProvider` requires exactly one method, and that stays
 * so: a provider that can only verify a presented login is a complete provider. Whoever can also
 * query the external system without anyone presenting a password implements this interface in
 * addition.
 *
 * WHAT FOR: whoever disappears from the directory can no longer get in — that follows by itself.
 * Their Contentfly account remains, however, and with it a refresh token that keeps fetching fresh
 * access JWTs until its time limit. A user removed by HR therefore keeps working until a time limit
 * expires that nobody chose for this purpose. `appcms:provider:abgleich` closes that gap — and needs
 * exactly this question for it.
 *
 * NOT EVERY PROVIDER CAN ANSWER IT. An OIDC provider, for instance, verifies a token the client
 * brings along; without a token it has no means to ask about an identifier. It therefore does not
 * implement this interface, and the sync skips it — visibly, not silently.
 */
interface UserExistenceCheck
{
    /**
     * Does the external system still know this identifier?
     *
     * THREE ANSWERS, AND THE THIRD IS THE MOST IMPORTANT:
     *
     *   true   present
     *   false  no longer present
     *   null   **cannot tell right now** — directory unreachable, service account rejected, timeout
     *
     * An outage must not look like a deleted user. If the answer were a `bool`, an unreachable
     * directory would have to be read either as `true` (then the sync never takes effect) or as
     * `false` (then a network error locks out the whole workforce). Both are wrong, hence the third
     * answer.
     */
    public function knowsIdentifier(string $identifier): ?bool;
}
