<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Entity\Permission as PermissionEntity;
use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * The permissions Contentfly enforces: read, write, delete.
 *
 * **`canExport()` and `getExtended()` were dropped with `000-000-0012`.** Both lived in the
 * `permissions` block of the schema and were **checked nowhere**: a user with `export = 0`
 * read, wrote and deleted exactly as before; an `extended` entry restricted no response.
 *
 * REMOVED RATHER THAN ENFORCED. `canExport` would have needed an endpoint that no longer
 * exists — its consumer was the `ExportController`, deleted in `012-001-0003`. `getExtended`
 * would have had to restrict the API's field selection; that would have been new
 * functionality, not a cleanup.
 *
 * REMOVED RATHER THAN KEPT AS METADATA. That was the third option, and it is the most
 * dangerous one: a permission that the server publishes but does not enforce looks like a
 * guarantee. A client that reads `export: false` and hides the button considers itself
 * secured — anyone calling the API directly is unaffected by it. Publishing something that
 * guarantees nothing is worse than leaving it out.
 *
 * THE COLUMNS STAY. `pim_permission.export` and `pim_permission.extended` are not touched; an
 * existing project may have values in them, and throwing data away is the irreversible
 * direction. They remain readable via `Areanet\PIM\Entity\Permission` — just without any claim
 * by the framework about what they do. They do nothing.
 *
 * THE LEVEL COLLAPSE OF `canExport` WAS NOT REPAIRED, but removed together with the field.
 * It read `return ($permission->getExport() == 2)` — only `ALL` counted as allowed, and because
 * the constants are not ordered ascending (`NONE` 0, `OWN` 1, `ALL` 2, `GROUP` 3), of all
 * things `GROUP` yielded `false`. Any repair would have decided which users are allowed in
 * future — for a permission nobody checks. Whoever brings the export back then decides that
 * for an endpoint that exists.
 */
class Permission
{
    /**
     * How much each level allows, from least to most. The constants themselves are NOT ordered
     * that way (`GROUP` is 3, `ALL` is 2), so they must never be compared as numbers.
     *
     * A value outside the four is ranked like ALL: every caller treats a level it does not
     * recognise as unrestricted, so ranking it lower would claim a restriction nobody enforces.
     */
    private const RANK = array(
        PermissionEntity::NONE  => 0,
        PermissionEntity::OWN   => 1,
        PermissionEntity::GROUP => 2,
        PermissionEntity::ALL   => 3,
    );

    public static function isReadable(User $user, $entityName){
        return self::is('readable', $user, $entityName);
    }

    public static function isWritable(User $user, $entityName){
        return self::is('writable', $user, $entityName);
    }

    public static function isDeletable(User $user, $entityName){
        return self::is('deletable', $user, $entityName);
    }

    protected static function is($mode, User $user, $entityName, $lang = null)
    {
        $entityName = str_replace(array('Custom\\Entity\\', 'Areanet\\PIM\\Entity\\'), array('', 'PIM\\'), $entityName);

        $method= 'get'.ucfirst($mode);

        if($user->getIsAdmin()) return 2;

        if($user->getGroup() === null) return 0;

        /*
         * SEVERAL ROWS FOR ONE ENTITY: THE MOST RESTRICTIVE COUNTS (000-000-0088).
         *
         * This used to return the first matching row. The association has no ORDER BY, and with
         * GUID ids MySQL returns a group's rows in id order — at random. Existing data has such
         * pairs: before 000-000-0070, every write of a group's permissions added a `PIM\Tag` row
         * at ALL next to an explicit `PIM\Tag` entry, and chance decided per group which counted.
         *
         * The most restrictive row wins, per mode, because only that reading makes an explicit
         * restriction count without the group being saved again. A single row counts as it is.
         * New duplicates cannot arise any more: PermissionsType::toDatabase() rejects them.
         */
        $level = false;
        foreach($user->getGroup()->getPermissions() as $permission){
            if($permission->getEntityName() != $entityName){
                continue;
            }

            $candidate = $permission->$method();
            if($level === false || self::rank($candidate) < self::rank($level)){
                $level = $candidate;
            }
        }

        return $level;
    }

    private static function rank($level): int
    {
        return self::RANK[$level] ?? self::RANK[PermissionEntity::ALL];
    }
}
