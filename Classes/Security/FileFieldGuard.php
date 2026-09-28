<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\File\UploadValidator;
use Areanet\PIM\Classes\Messages;

/**
 * THE NAME OF A FILE IS NOT FREE TEXT (015-000-0003).
 *
 * `PIM\File.name` is the only part of a file's path that does not come from the framework: the
 * directory is `data/files/<id>`, the size prefix is chosen from an allowlist, and the name is
 * whatever the column says. Through `/api/update` and `/api/insert` that column was writable like
 * any other string — `StringType::toDatabase()` stores what it is handed, and `UploadValidator`
 * is not on that path at all.
 *
 * Whoever held the read and write right on `PIM\File` — the same right an upload needs — could
 * therefore upload any file, set its name to `../../custom/config.php`, and read the database
 * credentials out of `/api/all` with `filedata: ["org"]`. The size `org` adds no prefix, so the
 * `../` segments stood at the front of the name and left the directory.
 *
 * `type` is guarded with it. It selects the image processor and is written into the response's
 * `Content-Type`; a value with a path part or a line break in it has no legitimate use, and the
 * column exists to be set by the upload, not by hand.
 *
 * This is the first of two layers. The second is `File\FilePath`, which proves the containment
 * again at every filesystem access — because a record written before today may already carry a
 * name that this guard would now refuse.
 *
 * Only API writes pass through here — `Api::doInsert()` and `doUpdate()`. The upload path builds
 * the name itself, through `UploadValidator`, and satisfies this rule by construction.
 */
final class FileFieldGuard
{
    /**
     * @param mixed $data the request's `data` — not always an array: `/api/insert` without it
     *                    passes `null`, and what that answers is decided by the caller
     */
    public static function assertMayWrite(string $entity, mixed $data): void
    {
        if ($entity !== 'PIM\\File' || !is_array($data)) {
            return;
        }

        $validator = new UploadValidator();

        if (array_key_exists('name', $data) && !$validator->isStorableName(self::text($data['name']))) {
            self::deny('PIM\\File::name');
        }

        if (array_key_exists('type', $data) && !self::isPlainType(self::text($data['type']))) {
            self::deny('PIM\\File::type');
        }
    }

    /**
     * A content type as the upload path produces it: `type/subtype`, no path part, no control
     * character. Checked by shape and not against a list — a project may store anything, and
     * what is being kept out is a value that is not a type at all.
     */
    private static function isPlainType(string $type): bool
    {
        return $type !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9.+_-]*/[A-Za-z0-9][A-Za-z0-9.+_-]*$#', $type) === 1;
    }

    /** A scalar as a string; anything else is not a name and not a type. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function deny(string $what): never
    {
        throw new ContentflyException(
            Messages::contentfly_file_invalid_type,
            $what,
            Messages::contentfly_status_bad_request
        );
    }
}
