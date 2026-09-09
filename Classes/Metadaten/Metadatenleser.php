<?php
namespace Areanet\PIM\Classes\Metadaten;

use Doctrine\Common\Annotations\AnnotationReader;

/**
 * Der eine Zugang zu den Metadaten einer Entity (010-001-0002).
 *
 * WOZU ES IHN GIBT. Bis hierher bauten sich drei Stellen jede fuer sich einen
 * `AnnotationReader`: `Classes/Api.php`, `Classes/Types/JoinBidirectionalType.php` und ein
 * ungenutzter Import im `ApiController`. Solange sie einzeln standen, haette der Wechsel von
 * Annotationen auf Attribute alle gleichzeitig treffen muessen — sonst lesen sie Docblocks,
 * die es nicht mehr gibt. Hinter einer Klasse ist der Wechsel **eine** Aenderung an **einer**
 * Stelle, und die Umstellung der Entities zerfaellt in Tasks, die einzeln gruen sind.
 *
 * Dieselbe Konstruktion wie `Kernel\Application` gegenueber Silex in `009-001`, und aus
 * demselben Grund: erst die Fuge, dann der Tausch dahinter.
 *
 * WAS ER HEUTE TUT. Er liest **Annotationen**, wie bisher. Dieser Task aendert kein Verhalten;
 * er schafft nur die Stelle, an der `010-001-0003` umschaltet.
 *
 * WAS DAS RUECKGABEFORMAT ANGEHT: eine **Liste** von Metadaten-Objekten, in der Reihenfolge der
 * Deklaration. Genau das lieferte der `AnnotationReader`, und die Aufrufer verlassen sich
 * darauf — `Api.php` schluesselt sie selbst nach Klassennamen um (`get_class()`) und sortiert
 * mit `krsort()`. Diese Klasse nimmt ihnen das **nicht** ab: Die Umschluesselung ist Teil des
 * Vertrags, an dem 21 `Type`-Klassen haengen, und sie hier zu veraendern waere ein zweiter
 * Umbau im selben Schritt.
 */
final class Metadatenleser
{
    /** @var AnnotationReader */
    private $leser;

    public function __construct()
    {
        $this->leser = new AnnotationReader();
    }

    /**
     * Die Metadaten einer Klasse.
     *
     * @return object[] Liste, Reihenfolge der Deklaration
     */
    public function klasse(\ReflectionClass $klasse): array
    {
        return $this->leser->getClassAnnotations($klasse);
    }

    /**
     * Die Metadaten einer Eigenschaft.
     *
     * @return object[] Liste, Reihenfolge der Deklaration
     */
    public function eigenschaft(\ReflectionProperty $eigenschaft): array
    {
        return $this->leser->getPropertyAnnotations($eigenschaft);
    }
}
