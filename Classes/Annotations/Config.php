<?php
namespace Areanet\PIM\Classes\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Datenrelevante Konfiguration einer Entity oder einer ihrer Eigenschaften.
 *
 * Von den ursprünglich 24 Feldern beschrieben die meisten Eingabemasken der
 * PIM-Oberfläche. Mit ihr sind sie entfallen — ersatzlos, ohne Duldungsphase. Übrig
 * bleibt, was das Verhalten der API steuert: Verschlüsselung, Sync, Filter, Sortierung
 * und das Datenmodell selbst. Die Liste der gestrichenen Felder und der Weg für
 * Bestandsprojekte stehen in `an_project/docs/pim-annotationen-migration.md`.
 *
 * @Annotation
 */
final class Config extends Annotation
{
    /**
     * Nimmt die Entity aus der Sync-API heraus — `Classes/Api.php`.
     *
     * @var boolean
     */
    public $excludeFromSync = false;

    /**
     * Verschlüsselt den Wert in der Datenbank — `StringType`, `TextareaType`.
     *
     * @var boolean
     */
    public $encoded = false;

    /**
     * @var boolean
     */
    public $unique = false;

    /**
     * Gibt die Eigenschaft für die Filter der API frei — `Classes/Type.php`.
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

}
