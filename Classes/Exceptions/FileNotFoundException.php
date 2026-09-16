<?php
namespace Areanet\PIM\Classes\Exceptions;

/**
 * A file, a file size setting or a parameter of the file endpoints is missing.
 *
 * IT IS A ContentflyException SINCE `011-001-0004`, and that is not cosmetic. Before, it extended
 * `\Exception`, and the error handler carried a branch of its own for it: a plain-text `404` with
 * the message as the whole body — `text/html` with `contentfly_general_not_found` in it. That was
 * the last response of the framework outside the envelope, and it hit a JSON endpoint,
 * `/file/overwrite`, not just the file delivery.
 *
 * Through the shared parent it goes the normal way: the envelope's entry gets the `Messages` key as
 * its `code`, exactly as for every other Contentfly exception, and the `404` comes from the code
 * passed here instead of from an `X-Status-Code` header.
 */
class FileNotFoundException extends ContentflyException
{
    public function __construct($message = 'File not found', $value = null) {
        parent::__construct($message, $value, 404);
    }
}
