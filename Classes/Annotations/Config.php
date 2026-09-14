<?php
namespace Areanet\PIM\Classes\Annotations;

use Attribute;

/**
 * Data-relevant configuration of an entity or of one of its properties.
 *
 * Of the originally 24 fields, most described input forms of the PIM interface. They were
 * dropped along with it — without replacement, without a grace period. What remains is what
 * controls the behaviour of the API: encryption, sync, filters, sorting and the data model
 * itself. The list of removed fields and the path for existing projects are in
 * `an_project/docs/pim-annotationen-migration.md`.
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
 * **Both targets**, and that is measured: `@PIM\Config` is placed on classes (`Group`, `Nav`,
 * `Tag`, `File` — `labelProperty`, `sortBy`, `excludeFromSync`) and on properties
 * (`unique`, `isFilterable`). With 25 of 36 usages it is the most frequent `@PIM`
 * annotation in the tree.
 *
 * @Annotation
 * @NamedArgumentConstructor
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Config
{
    /**
     * Removes the entity from the sync API — `Classes/Api.php`.
     *
     * @var boolean
     */
    public $excludeFromSync = false;

    /**
     * Encrypts the value in the database — `StringType`, `TextareaType`.
     *
     * @var boolean
     */
    public $encoded = false;

    /**
     * @var boolean
     */
    public $unique = false;

    /**
     * Enables the property for the API's filters — `Classes/Type.php`.
     *
     * @var boolean
     */
    public $isFilterable = false;

    /**
     * @var boolean
     */
    public $i18n_universal = false;

    /**
     * Property whose value designates an entity. Ends up as `modelLabel` in the
     * log and determines which field of joined objects the API includes.
     *
     * @var string
     */
    public $labelProperty = '';

    /**
     * Currently only `tree` — controls the tree queries in `Classes/Api.php`.
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
     * Accepts the values as named arguments.
     *
     * The defaults are the same as on the fields — anyone who omits an argument gets exactly
     * the value the annotation gave them before. Deliberately **without** type declarations:
     * under annotations every field was untyped, and a type added here would be a new
     * restriction for existing projects, not merely a clarification.
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
