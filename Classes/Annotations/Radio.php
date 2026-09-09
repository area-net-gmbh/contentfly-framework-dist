<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Einfachauswahl auf einer ManyToOne-Beziehung.
 *
 * Bleibt trotz des UI-Rueckbaus: die Annotation waehlt den `RadioType` aus, der die
 * Beziehung aufloest und die Leseberechtigungen prueft, und `group` benennt die
 * OptionGroup, die dabei angelegt wird. Die reinen Darstellungsfelder sind entfallen.
 *
 * Im Framework selbst nicht verwendet (`010-001-0001`) — sie gehoert Projekten.
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
final class Radio
{
    /**
     * @var string
     */
    public $group = null;

    /**
     * Nimmt die Werte als benannte Argumente entgegen.
     *
     * Die Vorgaben sind dieselben wie an den Feldern — wer ein Argument weglaesst, bekommt
     * genau den Wert, den die Annotation ihm bisher gab. Bewusst **ohne** Typangaben: Unter
     * Annotationen war jedes Feld untypisiert, und ein hier ergaenzter Typ waere eine neue
     * Einschraenkung fuer Bestandsprojekte, nicht bloss eine Praezisierung.
     */
    public function __construct($group = null)
    {
        $this->group = $group;
    }
}
