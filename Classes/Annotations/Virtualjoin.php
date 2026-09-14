<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Reference to an entity that is not mapped as a Doctrine relation.
 *
 * Used in `Entity\Base` for `userCreated`/`groupCreated` and in `Entity\Log`.
 *
 * It is **annotation and attribute at the same time** (`010-001-0001`). Doctrine's own
 * mapping classes are exactly that in 2.20, and the reason is the same: as long as the entities
 * still carry docblocks, the `AnnotationReader` reads it; as soon as they have been converted
 * (`010-001-0003`), reflection reads it. This lets the conversion be split into steps that are
 * green one by one.
 *
 * `@NamedArgumentConstructor` is required for this: without the marker, the `DocParser` passes
 * the constructor **an array** instead of named arguments, and the class would no longer be
 * readable as an annotation.
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
     * Accepts the values as named arguments.
     *
     * The defaults are the same as on the fields — anyone who omits an argument gets exactly
     * the value the annotation gave them before. Deliberately **without** type declarations:
     * under annotations every field was untyped, and a type added here would be a new
     * restriction for existing projects, not merely a clarification.
     */
    public function __construct($targetEntity = '')
    {
        $this->targetEntity = $targetEntity;
    }
}
