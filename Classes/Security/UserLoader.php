<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads a user by their identifier (013-002-0001).
 *
 * THE COUNTERPART TO THE TOKEN HANDLER. A handler returns a `UserBadge` with an identifier; this
 * loader turns it into a user. It is available even when a handler has already loaded the user
 * itself — `AccessTokenAuthenticator` only uses it when the badge does not bring its own loader.
 *
 * A DEACTIVATED USER IS TREATED LIKE AN UNKNOWN ONE, with the same exception. That is not an
 * oversight: the story requires an invalid token to fail indistinguishably. If a separate exception
 * for "deactivated" were thrown here, the response would be an oracle for which accounts exist and
 * which are currently switched off.
 *
 * WIRED UP SINCE 013-002-0004. For two tasks it stood next to `checkToken()` and was checked on its
 * own; only when both halves were in place was the switch flipped — the same pattern as in epic
 * `010`. `checkToken()` no longer exists since then.
 */
final class UserLoader implements UserProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->em->getRepository(User::class)->findOneBy(array('alias' => $identifier));

        if (!$user instanceof User || !$user->getIsActive()) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    /**
     * Reloads the user from the database.
     *
     * This is called where a user is restored from a session. Contentfly has no session — it went
     * away with story `012-004` — so this path only exists for completeness. It reloads by the same
     * identifier and thereby checks the same conditions; a user deactivated in the meantime drops
     * out.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
