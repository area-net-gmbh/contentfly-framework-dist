<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Waehlt den `PermissionsType` fuer eine Eigenschaft aus — `Entity\Group::$permissions`.
 *
 * Ohne Felder: Sie ist ein reiner Schalter.
 *
 * Sie ist **Annotation und Attribut zugleich** (`010-001-0001`). Doctrines eigene
 * Mapping-Klassen sind in 2.20 genau das, und der Grund ist derselbe: Solange die Entities
 * noch Docblocks tragen, liest sie der `AnnotationReader`; sobald sie umgestellt sind
 * (`010-001-0003`), liest sie die Reflection. Der Umbau laesst sich dadurch in Schritte
 * zerlegen, die einzeln gruen sind.
 *
 * Ohne Felder braucht sie keinen Konstruktor und deshalb auch kein
 * `@NamedArgumentConstructor` — der Vermerk waere wirkungslos, weil der `DocParser` ihn
 * an `has_constructor` knuepft.
 *
 * @Annotation
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Permissions
{
}
