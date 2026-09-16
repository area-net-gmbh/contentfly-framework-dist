<?php
namespace Areanet\PIM\Classes\ORM;

use Composer\InstalledVersions;

/**
 * Where Doctrine writes its proxy classes — one directory per framework and ORM version (000-000-0049).
 *
 * THE PROXIES USED TO LIVE IN `data/cache/doctrine`, the directory Contentfly 1.x used as well. Since
 * 000-000-0047 a proxy is only rewritten when it is missing or older than its entity file, and that is a
 * comparison of dates:
 *
 * - After an update, the proxies of the **old** installation are younger than the entity files, which a
 *   Composer package carries with the date of its archive. Measured on the data of the existing project
 *   UFP: the ORM 2 proxy was loaded and the request died with
 *   `Interface "Doctrine\ORM\Proxy\Proxy" not found`.
 * - The same holds for a later framework update that changes an entity: an old proxy can look current.
 *
 * A DIRECTORY PER VERSION TAKES THE DATE OUT OF THE DECISION. The name is derived from what the two
 * packages report — their version and, where there is one, the exact reference. A new version writes into
 * a new directory, and nothing that was written for another version is ever loaded.
 *
 * Old directories are not deleted: during a deployment the previous installation may still be serving
 * requests out of one. They can be removed at any time, and `data/cache/doctrine` with them.
 */
final class ProxyDirectory
{
    public const BASE = '/cache/proxies';

    public static function path(string $dataDir): string
    {
        return rtrim($dataDir, '/') . self::BASE . '/' . self::identifier();
    }

    /** Twelve hex characters — enough to tell versions apart, short enough to read in a path. */
    public static function identifier(): string
    {
        $versions = array();

        foreach (array('areanet/contentfly', 'doctrine/orm') as $package) {
            $versions[$package] = self::describe($package);
        }

        return self::fingerprint($versions);
    }

    /**
     * The name of the directory for a given set of versions — the part that can be checked without a
     * Composer installation.
     *
     * @param array<string,string> $versions package => version
     */
    public static function fingerprint(array $versions): string
    {
        $parts = array();

        foreach ($versions as $package => $version) {
            $parts[] = $package . ':' . $version;
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    private static function describe(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            // No Composer runtime (a copied tree, a test harness): the version of the framework itself
            // is the best that is available, and it changes with every release.
            return defined('APP_VERSION') ? (string) constant('APP_VERSION') : 'unknown';
        }

        return (string) InstalledVersions::getPrettyVersion($package)
            . '@' . (string) InstalledVersions::getReference($package);
    }
}
