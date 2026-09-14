<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The contract for logging in through an external system (013-004-0001).
 *
 * ONE DUTY, AND IT IS THE ONLY ONE A PROJECT HAS: verify against the external system. Everything
 * else is contributed by the framework — finding or creating the user, setting groups and the admin
 * flag, issuing the token, logging out.
 *
 * THAT IS THE BASIC IDEA OF THE OLD `LoginManager`, and it stays. What goes are its design flaws: it
 * required a finished `User` entity and thereby pushed provisioning into the project, where it was
 * reinvented every time — with `setPass($alias)` as the most prominent result.
 *
 * THE PROVIDER DOES NOT TOUCH THE DATABASE. It receives the request and returns an
 * `ExternalIdentity` — or `null` if it recognised nobody. Throwing an exception here leads to the
 * same result: the caller only learns that it was not enough.
 */
interface LoginProvider
{
    /**
     * Verifies the request's credentials against the external system.
     *
     * @return ExternalIdentity|null null means rejected — without giving a reason to the outside
     */
    public function authenticate(Request $request): ?ExternalIdentity;
}
