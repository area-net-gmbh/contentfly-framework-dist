<?php
namespace Areanet\PIM\Classes\ORM\Id;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Ramsey\Uuid\Uuid;

/**
 * Generates the GUID ids in PHP instead of in the database (009-005-0002).
 *
 * UNTIL DBAL 3 CAME, MYSQL GENERATED THEM. `Entity\Base` carried
 * `@ORM\GeneratedValue(strategy="UUID")`, and Doctrine's `ORM\Id\UuidGenerator` asked the
 * database via `AbstractPlatform::getGuidExpression()` — i.e. `SELECT UUID()`. The method no
 * longer exists in DBAL 3, and Doctrine's own source code says what belongs in its
 * place:
 *
 *     @deprecated use an application-side generator instead
 *
 * Without this replacement every entity inheriting from `Base` broke — 173 of 249 tests
 * (`009-005-0001`).
 *
 * VERSION 4, NOT 1. The ids MySQL has generated so far are version 1
 * (`7697af14-ac72-11f1-…`). Nevertheless v4 is generated here, for two reasons:
 *
 * 1. **The tree already does so in one place.** `Api.php` has always generated an id itself for
 *    `BaseI18n` objects, with `Uuid::uuid4()`. So there never was a uniform origin; v4
 *    everywhere makes it uniform, v1 everywhere would have meant converting the second place too.
 * 2. **A v1 UUID carries the server's MAC address and the time of generation.** Ids
 *    appear in every API response. That is a small but unnecessary disclosure, and
 *    abolishing it costs nothing here.
 *
 * Nothing changes for existing data: both versions are 36 characters in the same
 * column, old rows keep their ids, and nothing in the code reads out the version.
 *
 * NOT CHOSEN: version 7. It would be time-ordered and therefore better for the index of a
 * `varchar` primary key column than the randomness of v4. That is a performance decision
 * for which there is no measurement here — and it would bring a third id form into a tree that
 * is just being brought down to one. Revisit if someone measures the index load.
 */
class UuidGenerator extends AbstractIdGenerator
{
    /**
     * @param object|null $entity
     *
     * @return string A version 4 UUID, 36 characters with hyphens.
     */
    public function generateId(EntityManagerInterface $em, $entity): string
    {
        return Uuid::uuid4()->toString();
    }
}
