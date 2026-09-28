<?php
namespace Areanet\PIM\Classes\File;

/**
 * A file of a `PIM\File` record stays inside that record's own directory (015-000-0002 … 0003).
 *
 * ── Why ────────────────────────────────────────────────────────────────────────────────
 *
 * The `name` column of a `PIM\File` was writable through the generic API — `/api/update` and
 * `/api/insert` handed it to `StringType::toDatabase()`, which stores what it is given, and
 * `UploadValidator` never saw it. Every place that reads a file then built its path by
 * concatenation: `getPath($file).'/'.$sizePrefix.$file->getName()`. For the size `org` there is no
 * prefix, so `../` segments in the name left `data/files/<id>/` unhindered. Setting the name to
 * `../../custom/config.php` and asking `/api/all` for `filedata: ["org"]` returned the database
 * credentials, base64-encoded.
 *
 * ── Why a class of its own, and not a method on the backend ─────────────────────────────
 *
 * `BackendInterface` is implemented by projects. Adding a method to it would break every custom
 * backend on upgrade, for a rule that has nothing to do with where the bytes are stored. A static
 * check next to it works with any backend and cannot be forgotten by one.
 *
 * ── The rule ───────────────────────────────────────────────────────────────────────────
 *
 * TWO CHECKS, BECAUSE ONE OF THEM IS BLIND. The name must be a plain name — no separator, no NUL,
 * not `.` or `..` —, which is what makes joining it safe in the first place. And the joined path
 * is resolved: a symlink lying inside the directory would otherwise still point out of it. The
 * second check only applies to a file that exists; a thumbnail about to be written has no
 * resolution yet, and for it the first check is the whole guarantee.
 */
final class FilePath
{
    /**
     * Is this a record id in the strict format of the configured id strategy (015-000-0005)?
     *
     * THE ID OF A `PIM\\File` IS A DIRECTORY NAME. `FileSystem::getPath()` builds
     * `data/files/<id>` from it, and with `DB_GUID_STRATEGY` — the shipped default — the column
     * is a free string that `/file/upload` and `/api/insert` took straight from the request. An
     * id of `../cache/x` therefore moved the upload into `data/cache`, where `Api::getSchema()`
     * hands the schema cache to `unserialize()`; and it made `/api/delete` empty a directory
     * that was never a file's.
     *
     * `within()` below would already stop the path from leaving. This is the layer above it:
     * a record whose id is not an id has no business being created in the first place, and
     * refusing it at the write is an answer the caller can understand — a containment failure
     * further down is not.
     *
     * The strategy is a parameter, not read from the configuration: a rule that cannot be
     * measured from both sides is one nobody notices the loss of.
     */
    public static function isRecordId(mixed $id, bool $guidStrategy): bool
    {
        if (!is_scalar($id)) {
            return false;
        }

        $id = (string) $id;

        if ($guidStrategy) {
            // The shape `Uuid::uuid4()` produces, and nothing else. Not `Uuid::isValid()`: that
            // accepts braces and a `urn:uuid:` prefix, neither of which is a directory name.
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
        }

        // Auto-increment: a positive whole number, without a sign and without leading zeros.
        return preg_match('/^[1-9][0-9]*$/', $id) === 1;
    }

    /**
     * The absolute path of `$fileName` inside `$directory`, or null when it would leave it.
     *
     * Null is a refusal, not an error: the caller answers it the way it answers a missing file,
     * because from outside those two are the same thing and must stay indistinguishable.
     */
    public static function within(string $directory, string $fileName): ?string
    {
        $real = realpath($directory);
        if ($real === false) {
            return null;
        }

        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        // `/` and `\` both, and NUL: PHP cuts a path at the NUL byte, so a name may carry a
        // second one behind it that never reaches this check by eye.
        if (strpbrk($fileName, "/\\\0") !== false) {
            return null;
        }

        $path     = $real.DIRECTORY_SEPARATOR.$fileName;
        $resolved = realpath($path);

        if ($resolved !== false && !str_starts_with($resolved, $real.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
