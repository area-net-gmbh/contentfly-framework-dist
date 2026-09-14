<?php
namespace Areanet\PIM\Classes\Events;

use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;

/**
 * Attaches an index on `modified` to every entity.
 *
 * WHAT FOR: the sync endpoints (`/api/all`, `/api/deleted`) filter by `modified`. Without an
 * index that is a table scan per query — on a table that grows with the data.
 *
 * ── An entity WITHOUT `modified` is skipped (000-000-0028) ──────────────────────────────
 *
 * Up to this point the listener attached the index UNCONDITIONALLY. If the column was missing,
 * even the installation failed:
 *
 *     The installation failed: There is no column with name "modified" on table
 *     "pim_revoked_token".
 *
 * The message says WHAT is missing, but not WHO requires it — and this listener sits in a place
 * where nobody who has just written a new entity would look. Found during `013-003-0003`, and
 * getting there took half an hour.
 *
 * CHOSEN: skip, don't throw. Three reasons:
 *
 *   1. The index has a purpose, and that purpose is tied to the column. An entity without
 *      `modified` takes part in no sync query — an index for it would be pointless, not a
 *      loss.
 *   2. This would not be a misconfiguration. There is no reason to abort an installation
 *      because a project has created an entity without a timestamp; the project is entitled
 *      to do so.
 *   3. **Nothing changes for existing installations.** Re-measured on a fresh installation:
 *      16 tables carried the index before, 15 afterwards — and the difference is exactly
 *      `pim_revoked_token`, whose `modified` column was dropped in the same task, because it
 *      only existed to satisfy this listener. Every other table is unchanged.
 *
 * NOT CHOSEN: throwing with a message that names this listener. That would have been more
 * honest than the Doctrine message, but would still have aborted an installation for which
 * there is no reason to abort.
 *
 * That entities are expected to bring a `modified` timestamp is now documented in
 * `an_project/docs/dev-guide.md` — at the place where someone writing an entity looks.
 */
class LoadMetadata
{
    public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $eventArgs): void
    {
        $em             = $eventArgs->getEntityManager();
        $classMetadata  = $eventArgs->getClassMetadata();
        $className      = $classMetadata->getName();

        /*
         * Trees have been excluded for as long as the listener has existed: `BaseTree` and
         * `BaseI18nTree` bring their own indexes, and an additional one would be a duplicate there.
         */
        if (in_array('Areanet\PIM\Entity\BaseTree', $classMetadata->parentClasses)
            || in_array('Areanet\PIM\Entity\BaseI18nTree', $classMetadata->parentClasses)) {
            return;
        }

        // See the class comment: no field, no index — and no abort.
        if (!$classMetadata->hasField('modified')) {
            return;
        }

        $cmBuilder = new ClassMetadataBuilder($classMetadata);
        $cmBuilder->addIndex(array('modified'), 'modified_index');

        $em->getMetadataFactory()->setMetadataFor($className, $classMetadata);
    }
}
