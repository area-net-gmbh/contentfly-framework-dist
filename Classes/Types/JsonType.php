<?php
namespace Areanet\PIM\Classes\Types;

use Areanet\PIM\Classes\Type;

/**
 * Bildet Doctrines `json`-Spaltentyp auf das API-Schema ab (000-000-0017).
 *
 * WARUM ES DEN TYP BRAUCHT. Doctrine kennt `json` seit Langem; das Framework kannte ihn
 * nicht. Ein Feld mit `@ORM\Column(type="json")` fiel deshalb **still aus dem Schema**:
 * kein Eintrag, keine Warnung, kein Hinweis. Lesen lieferte das Feld nicht, Schreiben
 * scheiterte mit `contentfly_general_unknown_property` — und die Vorlage `custom/` fuehrte
 * genau so ein Feld vor. Wer sich an ihr orientierte, lief in dieselbe Wand.
 *
 * WARUM SO WENIG CODE. Die Umwandlung macht Doctrine: Beim Lesen kommt aus der Spalte ein
 * PHP-Array, beim Schreiben kodiert Doctrine es zurueck nach JSON. Getter und Setter der
 * Basisklasse reichen deshalb aus — `fromDatabase()` und `toDatabase()` sind bewusst nicht
 * ueberschrieben. Ein eigenes json_encode() hier wuerde doppelt kodieren.
 *
 * WAS DER TYP NICHT TUT. Er prueft die Struktur des Werts nicht. Ein `json`-Feld nimmt jede
 * Struktur an, die sich kodieren laesst — das ist der Zweck des Spaltentyps. Wer eine Form
 * erzwingen will, tut das im Projektcode.
 */
class JsonType extends Type
{
    public function getPriority()
    {
        return 10;
    }

    public function getAlias()
    {
        return 'json';
    }

    public function getAnnotationFile()
    {
        return null;
    }

    public function doMatch($propertyAnnotations)
    {
        if (!isset($propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
            return false;
        }

        return $propertyAnnotations['Doctrine\\ORM\\Mapping\\Column']->type == 'json';
    }
}
