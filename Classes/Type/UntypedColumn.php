<?php
namespace Areanet\PIM\Classes\Type;

/**
 * A column that none of the registered Contentfly types matched (000-000-0046).
 *
 * Since 000-000-0017 such a field no longer drops out of the API schema silently: it raises an
 * E_USER_WARNING that names entity, property and column type. That stays the rule — for a type the
 * framework does not know, the warning is the only hint why a field cannot be read or written.
 *
 * BINARY COLUMNS ARE THE EXCEPTION, AND THEY ARE LEFT OUT ON PURPOSE. JSON cannot carry arbitrary
 * bytes; a `blob` or `binary` value would break `json_encode()` rather than reach a client. Such a
 * column is data for the server — an embedding vector, a hash, a file stored in the database — not a
 * field of the API. Warning about it says nothing the author does not know.
 *
 * And the warning did harm: with APP_DEBUG the bootstrap sets display_errors=1, so it was printed in
 * front of the JSON of every request that built the schema. Found on the existing project UFP
 * (007-005-0004), whose `AI\VectorDocument::embedding` is a blob — every login answered text/html.
 */
final class UntypedColumn
{
    /** Column types that are deliberately not part of the API schema. */
    public const NON_API_COLUMN_TYPES = array('blob', 'binary');

    public static function report(string $entityName, string $property, ?string $columnType): void
    {
        if (in_array($columnType, self::NON_API_COLUMN_TYPES, true)) {
            return;
        }

        trigger_error(
            sprintf(
                'No Contentfly type matches %s::%s (column type "%s") — the field is missing from the API schema.',
                $entityName,
                $property,
                $columnType
            ),
            E_USER_WARNING
        );
    }
}
