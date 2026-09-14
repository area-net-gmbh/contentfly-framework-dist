<?php
namespace Areanet\PIM\Classes;

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

        foreach($user->getGroup()->getPermissions() as $permission){
            if($permission->getEntityName() == $entityName){
                return $permission->$method();
            }
        }

        return false;
    }
}
