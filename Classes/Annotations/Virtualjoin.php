<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Verweis auf eine Entity, die nicht als Doctrine-Beziehung abgebildet ist.
 *
 * Benutzt in `Entity\Base` fuer `userCreated`/`groupCreated` und in `Entity\Log`.
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
 * @Annotation
 * @NamedArgumentConstructor
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Virtualjoin
{
    /**
     * @var string
     */
    public $targetEntity = '';

    /**
     * Nimmt die Werte als benannte Argumente entgegen.
     *
     * Die Vorgaben sind dieselben wie an den Feldern — wer ein Argument weglaesst, bekommt
     * genau den Wert, den die Annotation ihm bisher gab. Bewusst **ohne** Typangaben: Unter
     * Annotationen war jedes Feld untypisiert, und ein hier ergaenzter Typ waere eine neue
     * Einschraenkung fuer Bestandsprojekte, nicht bloss eine Praezisierung.
     */
    public function __construct($targetEntity = '')
    {
        $this->targetEntity = $targetEntity;
    }
}
