<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\User;

/**
 * WHO MAY DO WHAT IS DECIDED BY ADMINS — ON THE WRITE SIDE TOO (000-000-0090).
 *
 * The read side has said so for a long time: `PermissionsType::fromDatabase()` shows a group's
 * permissions to admins only. The write side checked nothing of the kind. A non-admin with the
 * write right on one of three entities was an admin in all but name:
 *
 *  - `PIM\Group`: write the `permissions` of the own group — any right on any entity;
 *  - `PIM\Permission`: insert a row for the own group — the same, one row at a time;
 *  - `PIM\User`: set `isAdmin` on the own record — OWN is enough, doUpdate() lets a user write
 *    himself — or set ANOTHER user's password while confirming only the own. That included the
 *    admin's, and the next login was the admin's.
 *
 * The rule is the plain one: managing rights is for admins. A non-admin may still write these
 * entities — rename a group, change the own password with confirmation, create a user without a
 * group — but not the fields that decide what somebody may do, or who somebody is.
 *
 * A value that does not change is not a change: a client that posts a record back as it read it
 * keeps working. Compared is the value the record has now.
 *
 * Only API writes pass through here — Api::doInsert(), doUpdate() and doDelete(). Provisioning and
 * console commands write through the ORM and decide for themselves.
 */
final class RightsManagement
{
    /** Set on ANOTHER user, each of these takes over the account. */
    private const CREDENTIALS = array('pass', 'salt', 'loginManager', 'externalId');

    /**
     * @param Base|null $existing the record being updated, `null` on insert
     * @param mixed     $data     the request's `data` — not always an array: /api/insert without it
     *                            passes `null`, and what that answers is decided further on
     */
    public static function assertMayWrite(User $caller, string $entity, ?Base $existing, mixed $data): void
    {
        if ($caller->getIsAdmin()) {
            return;
        }

        if ($entity === 'PIM\\Permission') {
            self::deny($entity);
        }

        // Without an array there is no field to write; the caller reports that, not this class.
        if (!is_array($data)) {
            return;
        }

        if ($entity === 'PIM\\Group' && array_key_exists('permissions', $data)) {
            self::deny($entity.'::permissions');
        }

        if ($entity !== 'PIM\\User') {
            return;
        }

        $user = $existing instanceof User ? $existing : null;

        if (array_key_exists('isAdmin', $data) && (bool) $data['isAdmin'] !== (bool) $user?->getIsAdmin()) {
            self::deny('PIM\\User::isAdmin');
        }

        if (array_key_exists('group', $data) && self::id($data['group']) !== self::id($user?->getGroup()?->getId())) {
            self::deny('PIM\\User::group');
        }

        // A new user and the caller himself are not someone else's account. The own password
        // is guarded further on in doUpdate(), by the confirmation of the current one.
        if ($user === null || (string) $user->getId() === (string) $caller->getId()) {
            return;
        }

        foreach (self::CREDENTIALS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            /*
             * THE KEY IS THE CHANGE, NOT ITS VALUE (015-000-0001).
             *
             * This used to read `self::id($data['pass']) !== null`. `self::id()` turns
             * `null`, `''` and `[]` alike into `null` — so a `{"pass": null}` counted as
             * "no change" and walked past this barrier. At the end of the write path there
             * was still a `setPass('')`, and the next login was the victim's.
             *
             * A password is stored as a hash and cannot be compared: any value is a new one,
             * the empty one included. What counts is therefore that the key is there at all.
             */
            if ($field === 'pass') {
                self::deny('PIM\\User::pass');
            }

            if (!self::same($data[$field], $user->{'get'.ucfirst($field)}())) {
                self::deny('PIM\\User::'.$field);
            }
        }
    }

    public static function assertMayDelete(User $caller, string $entity): void
    {
        if (!$caller->getIsAdmin() && $entity === 'PIM\\Permission') {
            self::deny($entity);
        }
    }

    /** A scalar or `{"id": …}` as a comparable string; empty is `null`. */
    private static function id(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Are two credential values the same value?
     *
     * NOT VIA `self::id()` (015-000-0001): that method lumps `''` and `null` together. For
     * addressing a record this is right — `{"id": ""}` is not a reference —, for `salt`,
     * `loginManager` and `externalId` it is wrong. An `externalId: ""` on a record whose
     * `externalId` is `null` counted as unchanged, yet was written as `''`: a change to the
     * identity that this barrier did not see.
     *
     * The promise "a value that does not change is not a change" stands: what is compared is
     * still the value the record has now. Only empty and `null` are no longer the same.
     */
    private static function same(mixed $new, mixed $current): bool
    {
        if (is_array($new)) {
            $new = $new['id'] ?? null;
        }

        if (is_array($current)) {
            $current = $current['id'] ?? null;
        }

        if ($new === null || $current === null) {
            return $new === null && $current === null;
        }

        if (!is_scalar($new) || !is_scalar($current)) {
            return false;
        }

        return (string) $new === (string) $current;
    }

    private static function deny(string $what): never
    {
        throw new ContentflyException(
            Messages::contentfly_general_permission_denied,
            $what,
            Messages::contentfly_status_access_denied
        );
    }
}
