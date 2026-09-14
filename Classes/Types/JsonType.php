<?php
namespace Areanet\PIM\Classes\Types;

use Areanet\PIM\Classes\Type;

/**
 * Maps Doctrine's `json` column type onto the API schema (000-000-0017).
 *
 * WHY THE TYPE IS NEEDED. Doctrine has known `json` for a long time; the framework did not.
 * A field with `@ORM\Column(type="json")` therefore **dropped silently out of the schema**:
 * no entry, no warning, no hint. Reading did not return the field, writing failed with
 * `contentfly_general_unknown_property` — and the `custom/` template showcased exactly such
 * a field. Anyone who took it as a model ran into the same wall.
 *
 * WHY SO LITTLE CODE. Doctrine does the conversion: on read the column yields a PHP array,
 * on write Doctrine encodes it back to JSON. The getter and setter of the base class are
 * therefore sufficient — `fromDatabase()` and `toDatabase()` are deliberately not
 * overridden. A separate json_encode() here would encode twice.
 *
 * WHAT THE TYPE DOES NOT DO. It does not check the structure of the value. A `json` field
 * accepts any structure that can be encoded — that is the purpose of the column type.
 * Anyone who wants to enforce a shape does so in project code.
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


    public function doMatch($propertyAnnotations)
    {
        if (!isset($propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
            return false;
        }

        return $propertyAnnotations['Doctrine\\ORM\\Mapping\\Column']->type == 'json';
    }
}
