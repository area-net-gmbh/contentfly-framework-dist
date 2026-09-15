<?php
namespace Areanet\PIM\Classes\File;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Decides whether an upload may be stored, and under which name (000-000-0038).
 *
 * ── Why ────────────────────────────────────────────────────────────────────────────────
 *
 * `data/files/` lives inside the document root, and the `.htaccess` serves whatever exists there
 * straight from disk. Until this class, `FileController` took the extension from the client: an
 * upload named `probe-upload.php` was stored as `data/files/<id>/probe-upload.php`, and requesting
 * it answered `EXECUTED-42` — measured on a fresh installation on 2026-09-15. Any valid token was
 * enough, and a login provider may hand out tokens without credentials.
 *
 * Found at the existing project UFP (007-005-0001), which had closed the hole in its own copy of
 * the framework. This class follows that fix, with one deliberate difference below.
 *
 * ── Two layers ─────────────────────────────────────────────────────────────────────────
 *
 * 1. **The floor, always.** An executable extension in ANY dot-separated segment of the name, or a
 *    name that reconfigures the web server, is rejected. It is a constant and not configuration:
 *    no project setting can reopen it.
 * 2. **The whitelist, optional.** If `FILE_ALLOWED_TYPES` is set, the extension has to be listed,
 *    and the type detected FROM THE CONTENT has to be one the extension may carry.
 *
 * DECIDED ON 2026-09-15: the whitelist is not on by default. Contentfly is a general data store; a
 * `.txt` or `.pdf` has to be storable without configuration. UFP ships a whitelist of images only —
 * right for that project, too narrow for the framework.
 */
class UploadValidator
{
    /**
     * Never storable. Matched against every segment of the name, not only the last one: with a
     * loose `AddHandler` setup Apache hands `shell.php.jpg` to PHP because it recognises `.php`
     * anywhere in the name.
     */
    public const FORBIDDEN_EXTENSIONS = array(
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phtml', 'phtm',
        'pht', 'phar', 'inc', 'shtml', 'shtm', 'stm',
        'pl', 'pm', 'py', 'rb', 'cgi', 'fcgi', 'sh', 'bash', 'zsh', 'jsp', 'jspx', 'asp', 'aspx',
        'ashx', 'asmx', 'cfm', 'cfml',
        'htaccess', 'htpasswd',
    );

    /** Dangerous as a whole: they reconfigure the web server or PHP instead of being served. */
    public const FORBIDDEN_NAMES = array('.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config');

    /**
     * Checks the upload and returns what the caller stores.
     *
     * @return array{name: string, type: string} the name the framework builds, and the content
     *         type — detected from the file when a whitelist applies, otherwise the client's
     *         statement as before (it only selects the image processor, it grants nothing).
     *
     * @throws ContentflyException 400 without a file, 415 for a name or type that is not accepted
     */
    public function validate(?UploadedFile $file): array
    {
        if (!$file instanceof UploadedFile || !is_file($file->getPathname())) {
            throw new ContentflyException(Messages::contentfly_general_missing_params, 'file', 400);
        }

        $name      = $this->normalizeName($file->getClientOriginalName());
        $extension = $this->assertName($name);
        $type      = (string) $file->getClientMimeType();

        $allowed = $this->allowedTypes();
        if ($allowed !== null) {
            if ($extension === '' || !isset($allowed[$extension])) {
                throw new ContentflyException(Messages::contentfly_file_invalid_type, $extension, 415);
            }

            // From the content — the client's Content-Type is its own statement.
            $type = $this->detectType($file->getPathname());
            if (!in_array($type, $allowed[$extension], true)) {
                throw new ContentflyException(Messages::contentfly_file_invalid_type, $type, 415);
            }
        }

        return array('name' => $this->buildName($name, $extension), 'type' => $type);
    }

    /**
     * Whether a name that is already stored passes the floor. For records created before this
     * class existed: such a name must not survive a re-upload or an overwrite.
     */
    public function isAcceptableName(string $name): bool
    {
        try {
            $this->assertName($this->normalizeName($name));
        } catch (ContentflyException) {
            return false;
        }

        return true;
    }

    /** No directory parts, no NUL or control characters that could cut the name short on disk. */
    private function normalizeName(string $clientName): string
    {
        $name = str_replace(array("\0", '\\'), array('', '/'), $clientName);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return trim(basename($name));
    }

    /**
     * The floor. Returns the lower-cased extension ('' for a name without one).
     *
     * @throws ContentflyException
     */
    private function assertName(string $name): string
    {
        if ($name === '' || in_array(strtolower($name), self::FORBIDDEN_NAMES, true)) {
            throw new ContentflyException(Messages::contentfly_file_invalid_type, $name, 415);
        }

        foreach (explode('.', strtolower($name)) as $segment) {
            if (in_array(trim($segment), self::FORBIDDEN_EXTENSIONS, true)) {
                throw new ContentflyException(Messages::contentfly_file_invalid_type, $segment, 415);
            }
        }

        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /**
     * A sanitised base plus the checked extension. What the sanitiser removes is replaced by a
     * neutral base, so the result never collapses into a dotfile such as `.txt`.
     */
    private function buildName(string $name, string $extension): string
    {
        $base = strip_tags(pathinfo($name, PATHINFO_FILENAME));

        // With /u a name that is not valid UTF-8 yields null; then an ASCII-only pass reduces it
        // instead of losing it.
        $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $base);
        $clean = $clean === null ? (string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) : $clean;
        $clean = mb_strtolower(trim($clean, '-'), 'UTF-8');
        $clean = mb_substr($clean, 0, 120, 'UTF-8');

        if ($clean === '') {
            $clean = 'file';
        }

        return $extension === '' ? $clean : $clean . '.' . $extension;
    }

    /**
     * The content type from the file's bytes.
     *
     * With ext-fileinfo, not symfony/mime: the package is not in the tree, and a whitelist is
     * the only place that needs it. Without the extension a configured whitelist cannot be
     * checked — that fails closed with a message about the configuration, instead of silently
     * accepting what it was meant to filter.
     */
    private function detectType(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            throw new \RuntimeException('FILE_ALLOWED_TYPES is set, but the PHP extension fileinfo is not loaded; the content type of an upload cannot be checked.');
        }

        return strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
    }

    /**
     * `FILE_ALLOWED_TYPES` normalised, or null when no whitelist is configured. A forbidden
     * extension listed there is dropped — the configuration cannot reopen the floor.
     *
     * @return array<string, list<string>>|null
     */
    private function allowedTypes(): ?array
    {
        $configured = Adapter::getConfig()->FILE_ALLOWED_TYPES;

        if (!is_array($configured)) {
            return null;
        }

        $allowed = array();
        foreach ($configured as $extension => $types) {
            $extension = strtolower(ltrim((string) $extension, '.'));

            if (!in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
                $allowed[$extension] = array_map('strtolower', (array) $types);
            }
        }

        return $allowed;
    }
}
