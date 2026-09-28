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
