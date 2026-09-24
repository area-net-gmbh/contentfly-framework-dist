<?php
namespace Areanet\PIM\Classes\Types;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\Permission;
use Doctrine\Common\Collections\ArrayCollection;


class PermissionsType extends Type
{
    public function getPriority()
    {
        return 10;
    }

    public function getAlias()
    {
        return 'permissions';
    }


    public function doMatch($propertyAnnotations)
    {
        if (!isset($propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\Permissions'])) {
            return false;
        }

        return true;
    }

    public function processSchema($key, $defaultValue, $propertyAnnotations, $entityName)
    {
        $schema = parent::processSchema($key, $defaultValue, $propertyAnnotations, $entityName);

        $schema['dbtype'] = "integer";

        return $schema;
    }

    public function fromDatabase(Base $object, $entityName, $property, $flatten = false, $level = 0, $propertiesToLoad = array())
    {

        $getter = 'get' . ucfirst($property);

        if (!$object->$getter() instanceof \Doctrine\ORM\PersistentCollection) {
            return null;
        }


        $data = array();
        $permission = \Areanet\PIM\Entity\Permission::ALL;
        $subEntity = null;

        if (!$this->app['auth.user']->getIsAdmin()) {
            return null;
        }

        $subEntity = 'PIM\\Permission';

        if (in_array($property, $propertiesToLoad)) {
            foreach ($object->$getter() as $objectToLoad) {
                if ($permission == \Areanet\PIM\Entity\Permission::OWN && ($objectToLoad->getUserCreated() != $this->app['auth.user'] && !$objectToLoad->hasUserId($this->app['auth.user']->getId()))) {
                    continue;
                }

                if ($permission == \Areanet\PIM\Entity\Permission::GROUP) {
                    if ($objectToLoad->getUserCreated() != $this->app['auth.user']) {
                        $group = $this->app['auth.user']->getGroup();
                        if (!($group && $objectToLoad->hasGroupId($group->getId()))) {
                            continue;
                        }
                    }
                }

                // The permission row, not the group that holds it (000-000-0067) — both
                // branches returned the group's own id once per row.
                $data[] = $objectToLoad->getId();
            }
        } else {

            foreach ($object->$getter() as $objectToLoad) {
                if ($permission == \Areanet\PIM\Entity\Permission::OWN && ($objectToLoad->getUserCreated() != $this->app['auth.user'] && !$objectToLoad->hasUserId($this->app['auth.user']->getId()))) {
                    continue;
                }

                if ($permission == \Areanet\PIM\Entity\Permission::GROUP) {
                    if ($objectToLoad->getUserCreated() != $this->app['auth.user']) {
                        $group = $this->app['auth.user']->getGroup();
                        if (!($group && $objectToLoad->hasGroupId($group->getId()))) {
                            continue;
                        }
                    }
                }

                $data[] = $flatten
                    ? array("id" => $objectToLoad->getId())
                    : $objectToLoad->toValueObject($this->app, $subEntity, $flatten, $propertiesToLoad, ($level + 1), $propertiesToLoad);
            }
        }

        return $data;
    }

    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {
        // Before anything is written: the DELETE below empties the group, so a request that fails
        // later would leave it with no permissions at all (000-000-0088).
        $this->validateEntries($entityName, $property, $value);

        $this->em->persist($object);
        $this->em->flush();

        $query = $this->em->createQuery('DELETE FROM Areanet\PIM\\Entity\\Permission e WHERE e.group = ?1');
        $query->setParameter(1, $object);
        $query->execute();

        /*
         * THE HARD-WIRED PIM\Tag ROW IS GONE (000-000-0070).
         *
         * Every write of a group's permissions used to persist an extra row first — `PIM\Tag`
         * with read, write and delete at ALL — whatever the request asked for. Two consequences,
         * and the second is the worse one:
         *
         * 1. A group created through /api/insert or /api/update with `permissions` silently got
         *    full access to every tag. Nobody asked for it, and nothing said so.
         * 2. It sat next to an explicit `PIM\Tag` entry, and `Classes\Permission::is()` returned
         *    the FIRST entry matching the entity name. The association carries no ORDER BY; with
         *    GUID ids the database returns the rows in id order, so chance decided per group
         *    whether the explicit entry or the ALL row counted. Existing rows of that kind are
         *    resolved in `is()` since 000-000-0088.
         *
         * A leftover of the PIM interface removed in epic 012, which needed tags on files. The
         * row also set neither `export` nor `extended`, unlike the loop below — it was never
         * part of the contract, just a side effect.
         */
        foreach ($value as $config) {

            $pObject = new Permission();
            $pObject->setEntityName($config['name']);
            $pObject->setReadable($config['readable']);
            $pObject->setWritable($config['writable']);
            $pObject->setDeletable($config['deletable']);
            $pObject->setExport($config['export'] ?? 0);
            if (!empty($config['extended'])) {
                $pObject->setExtended(json_encode($config['extended']));
            } else {
                $pObject->setExtended('');
            }
            $pObject->setGroup($object);

            $this->em->persist($pObject);
        }


        $this->em->flush();
    }

    /**
     * THE REQUEST IS CHECKED WHOLE, BEFORE THE GROUP IS TOUCHED (000-000-0088).
     *
     * Until then nothing was checked. A missing key or an entry that is no object answered 500 —
     * after the DELETE, so an update left the group with no permissions. `null` or a string
     * answered 200 with the same loss and a PHP warning. The same entity twice wrote two rows,
     * and `Classes\Permission::is()` had to guess between them.
     *
     * Required per entry: `name` (a non-empty string), `readable`, `writable`, `deletable`.
     * `export` is optional and defaults to 0 — it has had no effect since 000-000-0012, and it was
     * only ever required by accident, as an undefined index. The levels themselves are not checked
     * here; that is a question of its own.
     *
     * An empty list is valid and clears the group's permissions.
     */
    private function validateEntries($entityName, $property, $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->reject($entityName, $property, 'expected a list of entries, got '.get_debug_type($value));
        }

        $seen = array();
        foreach ($value as $index => $config) {
            if (!is_array($config)) {
                $this->reject($entityName, $property, "entry $index is not an object");
            }

            if (!isset($config['name']) || !is_string($config['name']) || $config['name'] === '') {
                $this->reject($entityName, $property, "entry $index has no name");
            }

            foreach (array('readable', 'writable', 'deletable') as $key) {
                if (!array_key_exists($key, $config)) {
                    $this->reject($entityName, $property, "entry $index ({$config['name']}) has no $key");
                }
            }

            if (isset($seen[$config['name']])) {
                $this->reject($entityName, $property, "{$config['name']} appears more than once");
            }
            $seen[$config['name']] = true;
        }
    }

    private function reject($entityName, $property, string $reason): never
    {
        throw new ContentflyException(
            Messages::contentfly_general_invalid_params,
            sprintf('%s::%s — %s', $entityName, $property, $reason),
            Messages::contentfly_status_bad_request
        );
    }
}
