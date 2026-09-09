<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Datenrelevante Konfiguration einer Entity oder einer ihrer Eigenschaften.
 *
 * Von den urspruenglich 24 Feldern beschrieben die meisten Eingabemasken der
 * PIM-Oberflaeche. Mit ihr sind sie entfallen — ersatzlos, ohne Duldungsphase. Uebrig
 * bleibt, was das Verhalten der API steuert: Verschluesselung, Sync, Filter, Sortierung
 * und das Datenmodell selbst. Die Liste der gestrichenen Felder und der Weg fuer
 * Bestandsprojekte stehen in `an_project/docs/pim-annotationen-migration.md`.
 *
 * Sie ist **Annotation und Attribut zugleich** (`010-001-0001`). Doctrines eigene
 * Mapping-Klassen sind in 2.20 genau das, und der Grund ist derselbe: Solange die Entities
 * noch Docblocks tragen, liest sie der `AnnotationReader`; sobald sie umgestellt sind
 * (`010-001-0003`), liest sie die Reflection. Der Umbau laesst sich dadurch in Schritte
 * zerlegen, die einzeln gruen sind.
 *
 * `@NamedArgumentConstructor` ist dafuer noetig: Ohne den Vermerk uebergibt der `DocParser`
 * dem Konstruktor **ein Array** statt benannter Argumente, und die Klasse waere als
 * Annotation nicht mehr lesbar.
 *
 * **Beide Ziele**, und das ist gemessen: `@PIM\Config` steht an Klassen (`Group`, `Nav`,
 * `Tag`, `File` — `labelProperty`, `sortBy`, `excludeFromSync`) und an Eigenschaften
 * (`unique`, `isFilterable`). Sie ist mit 25 von 36 Verwendungen die haeufigste `@PIM`-
 * Annotation im Baum.
 *
 * @Annotation
 * @NamedArgumentConstructor
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Config
{
    /**
     * Nimmt die Entity aus der Sync-API heraus — `Classes/Api.php`.
     *
     * @var boolean
     */
    public $excludeFromSync = false;

    /**
     * Verschluesselt den Wert in der Datenbank — `StringType`, `TextareaType`.
     *
     * @var boolean
     */
    public $encoded = false;

    /**
     * @var boolean
     */
    public $unique = false;

    /**
     * Gibt die Eigenschaft fuer die Filter der API frei — `Classes/Type.php`.
     *
     * @var boolean
     */
    public $isFilterable = false;

    /**
     * @var boolean
     */
    public $i18n_universal = false;

    /**
     * Eigenschaft, deren Wert eine Entity bezeichnet. Landet als `modelLabel` im
     * Log und bestimmt, welches Feld verjointer Objekte die API mitliefert.
     *
     * @var string
     */
    public $labelProperty = '';

    /**
     * Aktuell nur `tree` — steuert die Baumabfragen in `Classes/Api.php`.
     *
     * @var string
     */
    public $type = '';

    /**
     * @var string
     */
    public $sortBy = null;

    /**
     * @var string
     */
    public $sortOrder = null;

    /**
     * @var string
     */
    public $sortRestrictTo = null;

    /**
     * Nimmt die Werte als benannte Argumente entgegen.
     *
     * Die Vorgaben sind dieselben wie an den Feldern — wer ein Argument weglaesst, bekommt
     * genau den Wert, den die Annotation ihm bisher gab. Bewusst **ohne** Typangaben: Unter
     * Annotationen war jedes Feld untypisiert, und ein hier ergaenzter Typ waere eine neue
     * Einschraenkung fuer Bestandsprojekte, nicht bloss eine Praezisierung.
     */
    public function __construct($excludeFromSync = false, $encoded = false, $unique = false, $isFilterable = false, $i18n_universal = false, $labelProperty = '', $type = '', $sortBy = null, $sortOrder = null, $sortRestrictTo = null)
    {
        $this->excludeFromSync = $excludeFromSync;
        $this->encoded = $encoded;
        $this->unique = $unique;
        $this->isFilterable = $isFilterable;
        $this->i18n_universal = $i18n_universal;
        $this->labelProperty = $labelProperty;
        $this->type = $type;
        $this->sortBy = $sortBy;
        $this->sortOrder = $sortOrder;
        $this->sortRestrictTo = $sortRestrictTo;
    }
}
