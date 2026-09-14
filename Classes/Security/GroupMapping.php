<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\Group;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps what an external system reports to Contentfly groups (013-004-0003).
 *
 * IN ONE PLACE AND IN THE CONFIGURATION. Before, `createManagedUser($alias, $group, $isAdmin)` took
 * group and admin flag as arguments — meaning every project decided on its own how to get from "the
 * external system says the user is in CN=Redaktion" to a Contentfly group. The result lived in project
 * code that nobody reads any more.
 *
 * WHAT IS NOT PART OF IT: rebuilding the Contentfly permission model. `Permission`, `I18nPermission`
 * and `Group` stay as they are; the mapping is ONTO them, not in their place. The same boundary as in
 * `013-002-0001`.
 *
 * IT APPLIES ON EVERY LOGIN, not only on creation. Whoever drops out of a group in the external system
 * drops out here as well on their next login. That is exactly why roles and groups are NOT in the JWT
 * (`013-003-0001`) — they would be frozen there until it expires.
 *
 * WHEN IN DOUBT, NO PERMISSIONS. Without a matching entry there is no group and no admin flag. A
 * mapping that grants permissions when in doubt goes in the wrong direction: the external system
 * should justify permissions, not their absence.
 */
final class GroupMapping
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Sets the user's group and admin flag from what the provider returned.
     */
    public function apply(string $provider, ExternalIdentity $identity, User $user): void
    {
        $rules = $this->rules($provider);

        if ($rules === null) {
            return;
        }

        /*
         * THE ADMIN FLAG IS ALWAYS SET, false included.
         *
         * Whoever drops out of the admin group in the external system should lose it here — on the
         * next login. Setting it only when there is a match would mean: once an administrator,
         * always an administrator.
         */
        $user->setIsAdmin($this->isAdmin($rules, $identity));

        $groupName = $this->groupName($rules, $identity);

        if ($groupName === null) {
            /*
             * No mapping and no default: the group is REMOVED, not left in place. Otherwise someone
             * would keep the permissions of a group the external system has removed them from.
             */
            $user->setGroup(null);
            $this->em->flush();

            return;
        }

        $group = $this->em->getRepository(Group::class)->findOneBy(array('name' => $groupName));

        if (!$group instanceof Group) {
            /*
             * A MISCONFIGURATION FAILS LOUDLY.
             *
             * Silently ignoring it would mean: the user gets in and has different permissions than
             * intended — and nobody learns why. Aborting the login is the safe direction, and the
             * message names the group.
             */
            throw new \RuntimeException(sprintf(
                'SECURITY_PROVIDER_GROUPS maps the provider "%s" to the group "%s", which does not '
                .'exist in pim_group.',
                $provider,
                $groupName
            ));
        }

        $user->setGroup($group);
        $this->em->flush();
    }

    /**
     * @return array{groups: array<string,string>, admin: list<string>, default: string|null}|null
     */
    private function rules(string $provider): ?array
    {
        $all = Adapter::getConfig()->SECURITY_PROVIDER_GROUPS;

        if (!is_array($all) || !isset($all[$provider]) || !is_array($all[$provider])) {
            return null;
        }

        $entry = $all[$provider];

        return array(
            'groups'  => is_array($entry['groups'] ?? null) ? $entry['groups'] : array(),
            'admin'   => is_array($entry['admin'] ?? null) ? array_values($entry['admin']) : array(),
            'default' => is_string($entry['default'] ?? null) ? $entry['default'] : null,
        );
    }

    /**
     * @param array{groups: array<string,string>, admin: list<string>, default: string|null} $rules
     */
    private function isAdmin(array $rules, ExternalIdentity $identity): bool
    {
        foreach ($rules['admin'] as $externalGroup) {
            if (in_array($externalGroup, $identity->groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first match in the configured order — or the default.
     *
     * THE ORDER IS A DECISION. A user can be in several external groups; Contentfly has exactly one
     * group per user. Which one wins is then stated in the configuration and not left to the whims
     * of a hash table.
     *
     * @param array{groups: array<string,string>, admin: list<string>, default: string|null} $rules
     */
    private function groupName(array $rules, ExternalIdentity $identity): ?string
    {
        foreach ($rules['groups'] as $externalGroup => $contentflyGroup) {
            if (in_array((string) $externalGroup, $identity->groups, true)) {
                return (string) $contentflyGroup;
            }
        }

        return $rules['default'];
    }
}
