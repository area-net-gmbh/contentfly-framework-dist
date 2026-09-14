<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Selects the `PermissionsType` for a property — `Entity\Group::$permissions`.
 *
 * No fields: it is a pure switch.
 *
 * It is **annotation and attribute at the same time** (`010-001-0001`). Doctrine's own
 * mapping classes are exactly that in 2.20, and the reason is the same: as long as the entities
 * still carry docblocks, the `AnnotationReader` reads it; as soon as they have been converted
 * (`010-001-0003`), reflection reads it. This lets the conversion be split into steps that are
 * green one by one.
 *
 * Having no fields, it needs no constructor and therefore no `@NamedArgumentConstructor`
 * either — the marker would have no effect, because the `DocParser` ties it to
 * `has_constructor`.
 *
 * @Annotation
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Permissions
{
}
