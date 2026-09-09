<?php
namespace Areanet\PIM\Classes\Metadaten;

/**
 * Der eine Zugang zu den Metadaten einer Entity.
 *
 * WOZU ES IHN GIBT (010-001-0002). Bis dahin bauten sich drei Stellen jede fuer sich einen
 * `AnnotationReader`: `Classes/Api.php`, `Classes/Types/JoinBidirectionalType.php` und ein
 * ungenutzter Import im `ApiController`. Solange sie einzeln standen, haette der Wechsel von
 * Annotationen auf Attribute alle gleichzeitig treffen muessen — sonst lesen sie Docblocks,
 * die es nicht mehr gibt. Hinter einer Klasse war der Wechsel **eine** Aenderung an **einer**
 * Stelle: genau diese Datei, mit 010-001-0003.
 *
 * WAS ER TUT (010-001-0003). Er liest **PHP-Attribute** per Reflection. Der `AnnotationReader`
 * ist damit aus dem Framework verschwunden.
 *
 * DIE SEMANTIK IST DIESELBE, und darauf kommt es an:
 *
 * - **Keine Vererbung.** `getClassAnnotations()` lieferte nur die Annotationen der Klasse
 *   selbst, nicht die ihrer Oberklassen; `ReflectionClass::getAttributes()` ebenso.
 * - **Eine Liste in Deklarationsreihenfolge**, kein Verzeichnis. `Api.php` schluesselt selbst
 *   nach Klassennamen um und sortiert mit `krsort()`. Das bleibt dort: 22 Dateien greifen mit
 *   `$propertyAnnotations['<voller Klassenname>']` darauf zu, und `getName()` eines Attributs
 *   liefert genau diese Form — nachgewiesen in 010-001-0001.
 * - **Geerbte Eigenschaften.** `Api.php` reicht `new ReflectionProperty($unterklasse, $name)`
 *   herein; PHP loest das auf die deklarierende Klasse auf, und die Attribute kommen von dort.
 *   Der `AnnotationReader` tat dasselbe.
 *
 * WARUM DIE FREMDEN ATTRIBUTE MITKOMMEN: Gelesen wird **alles**, nicht nur `@PIM`- und
 * `@ORM`-Angaben. Die `Type`-Klassen fragen gezielt nach ihren Schluesseln, und `Api.php`
 * verschickt jedes Metadatum als Ereignis (`pim.schema.after.propertyAnnotation`) — ein
 * Projekt kann daran eigene Angaben haengen. Zu filtern hiesse, diese Erweiterung stillzulegen.
 */
final class Metadatenleser
{
    /**
     * Die Metadaten einer Klasse.
     *
     * @return object[] Liste, Reihenfolge der Deklaration
     */
    public function klasse(\ReflectionClass $klasse): array
    {
        return $this->auflösen($klasse->getAttributes());
    }

    /**
     * Die Metadaten einer Eigenschaft.
     *
     * @return object[] Liste, Reihenfolge der Deklaration
     */
    public function eigenschaft(\ReflectionProperty $eigenschaft): array
    {
        return $this->auflösen($eigenschaft->getAttributes());
    }

    /**
     * Baut die Attribut-Objekte.
     *
     * Ein Attribut, dessen Klasse nicht geladen werden kann, wird **uebergangen** statt
     * geworfen. Das ist bewusst und bildet den alten Stand ab: Der `AnnotationReader` kannte
     * eine Ignorierliste und ging ueber alles hinweg, was er nicht aufloesen konnte —
     * `@param`, `@return` und jede Doc-Angabe eines Werkzeugs. Unter Attributen ist der Fall
     * seltener, aber nicht ausgeschlossen: Ein Attribut aus einem Paket, das nur in der
     * Entwicklung installiert ist, wuerde die ganze Schema-Erzeugung sonst zum Absturz
     * bringen.
     *
     * @param \ReflectionAttribute[] $attribute
     *
     * @return object[]
     */
    private function auflösen(array $attribute): array
    {
        $aus = array();

        foreach ($attribute as $eines) {
            if (!class_exists($eines->getName())) {
                continue;
            }

            $aus[] = $eines->newInstance();
        }

        return $aus;
    }
}
