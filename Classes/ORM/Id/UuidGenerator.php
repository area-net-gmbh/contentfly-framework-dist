<?php
namespace Areanet\PIM\Classes\ORM\Id;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Ramsey\Uuid\Uuid;

/**
 * Erzeugt die GUID-Ids in PHP statt in der Datenbank (009-005-0002).
 *
 * BIS DBAL 3 KAM, ERZEUGTE SIE MYSQL. `Entity\Base` trug
 * `@ORM\GeneratedValue(strategy="UUID")`, und Doctrines `ORM\Id\UuidGenerator` fragte die
 * Datenbank über `AbstractPlatform::getGuidExpression()` — also `SELECT UUID()`. Die Methode
 * gibt es in DBAL 3 nicht mehr, und Doctrines eigener Quelltext sagt, was an ihre Stelle
 * gehört:
 *
 *     @deprecated use an application-side generator instead
 *
 * Ohne diesen Ersatz brach jede Entity, die von `Base` erbt — 173 von 249 Tests
 * (`009-005-0001`).
 *
 * VERSION 4, NICHT 1. Die Ids, die MySQL bisher erzeugt hat, sind Version 1
 * (`7697af14-ac72-11f1-…`). Trotzdem wird hier v4 erzeugt, aus zwei Gründen:
 *
 * 1. **Der Baum tut es an einer Stelle schon.** `Api.php` erzeugt für `BaseI18n`-Objekte seit
 *    jeher selbst eine Id, mit `Uuid::uuid4()`. Es gab also nie eine einheitliche Herkunft;
 *    v4 überall macht sie einheitlich, v1 überall hätte die zweite Stelle mit umgestellt.
 * 2. **Eine v1-UUID trägt die MAC-Adresse des Servers und den Erzeugungszeitpunkt.** Ids
 *    stehen in jeder API-Antwort. Das ist eine kleine, aber unnötige Preisgabe, und sie
 *    abzuschaffen kostet hier nichts.
 *
 * Für vorhandene Daten ändert sich nichts: Beide Versionen sind 36 Zeichen in derselben
 * Spalte, alte Zeilen behalten ihre Ids, und nichts im Code liest die Version aus.
 *
 * NICHT GEWÄHLT: Version 7. Sie wäre zeitlich sortiert und damit besser für den Index einer
 * `varchar`-Primärschlüsselspalte als der Zufall von v4. Das ist eine Performance-Entscheidung,
 * für die hier keine Messung vorliegt — und sie brächte eine dritte Id-Form in einen Baum, der
 * gerade auf eine gebracht wird. Revidieren, wenn jemand die Indexlast misst.
 */
class UuidGenerator extends AbstractIdGenerator
{
    /**
     * @param object|null $entity
     *
     * @return string Eine UUID der Version 4, 36 Zeichen mit Bindestrichen.
     */
    public function generateId(EntityManagerInterface $em, $entity): string
    {
        return Uuid::uuid4()->toString();
    }
}
