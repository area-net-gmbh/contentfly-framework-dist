<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a user an external system has recognised — or finds them again (013-004-0002).
 *
 * IT BELONGS IN THE FRAMEWORK, NOT IN THE PROJECT. Before, `createManagedUser()` on the abstract
 * `LoginManager` class did this, called from project code — and every project could do it
 * differently or forget it. Since `013-004-0001` a provider has nothing to do with the database; it
 * says who is there, and this class turns that into a user.
 *
 * THREE THINGS THAT WERE WRONG BEFORE:
 *
 *   setPass($alias)   The password was the user name (finding A-6). Now it is locked.
 *   md5 prefix        `md5($class).'-'.$alias` made users unrecognisable. Now origin and identifier
 *                     live in their own fields, and the alias is readable.
 *   Resolution        Users were found through the scrambled alias. Now through provider AND
 *                     identifier — the same uniqueness, only readable.
 */
final class UserProvisioning
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The user for this external identity — existing or newly created.
     *
     * @param string $provider The NAME of the provider in the `LoginProviderRegistry`
     */
    public function findOrCreate(string $provider, ExternalIdentity $identity): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(array(
            'loginManager' => $provider,
            'externalId'   => $identity->identifier,
        ));

        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User();
        $user->setAlias(self::alias($provider, $identity->identifier));
        $user->setExternalId($identity->identifier);
        $user->setLoginManager($provider);

        /*
         * LOCKED BEFORE ANYTHING ELSE HAPPENS.
         *
         * Not only at the end of the method: a `return` or an exception in between — there is none
         * today, maybe tomorrow — would otherwise leave behind a row with the column's default value
         * and an account someone can take over.
         */
        $user->lockPassword();

        $user->setIsAdmin(false);
        $user->setIsActive(true);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * The Contentfly alias for an external identifier.
     *
     * `<provider>:<identifier>` instead of `md5(<class>)-<identifier>`. It has to be unique — the
     * column is — and it should be readable: whoever looks into `pim_user` wants to recognise who
     * this is and where they come from. The colon achieves both, which MD5 did not.
     */
    public static function alias(string $provider, string $identifier): string
    {
        return $provider.':'.$identifier;
    }
}
