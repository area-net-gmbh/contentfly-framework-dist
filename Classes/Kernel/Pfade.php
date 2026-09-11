<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * Die Verzeichnisse, die das Framework braucht — **uebergeben, nicht geraten** (007-001-0002).
 *
 * ── Was hier stand, und warum es weg musste ───────────────────────────────────────────
 *
 * `lib/contentfly/bootstrap.php`, Zeile 2:
 *
 *     const ROOT_DIR = __DIR__ . '/../..';
 *
 * Das Framework rechnete sich das Projektverzeichnis aus SEINER EIGENEN LAGE aus. Das stimmt
 * genau so lange, wie es unter `lib/contentfly/` im Projekt liegt. Sobald es als Paket unter
 * `vendor/areanet/contentfly/lib/contentfly/` liegt, zeigt `../..` nach `vendor/areanet/` —
 * und jeder Pfad darauf ins Leere.
 *
 * **Der Fehler waere ausserdem leise gewesen.** `__DIR__ . '/../..'` liefert immer einen Pfad,
 * er existiert nur nicht. Ein `file_exists()` darauf gibt `false` zurueck, und die Meldung, die
 * daraus entsteht, handelt von einer fehlenden Datei statt von einer falschen Wurzel. Wer das
 * Paket zum ersten Mal einbindet, sucht an der falschen Stelle. Dasselbe Muster wie in
 * `000-000-0029`: ein Fehler, der sich als etwas anderes ausgibt.
 *
 * Deshalb wirft jeder Zugriff hier, solange nichts gesetzt ist — und die Meldung sagt, WAS
 * fehlt und WER es zu setzen hat.
 *
 * ── Zwei Verzeichnisse, nicht eines ───────────────────────────────────────────────────
 *
 * `projekt()` ist das Verzeichnis des Projekts: dort liegen `custom/`, `data/`, `plugins/`.
 * Der Wert kommt vom Einstiegspunkt und kann nicht abgeleitet werden.
 *
 * `paket()` ist das Verzeichnis des Frameworks selbst. Es DARF aus `__DIR__` kommen — eine
 * Datei darf ihr eigenes Paket finden; sie darf nur nicht daraus schliessen, wo das Projekt
 * liegt. Genau diese Unterscheidung fehlte bisher, weshalb beides dieselbe Konstante war.
 *
 * ── Warum statisch und nicht ueber den Container ───────────────────────────────────────
 *
 * Erwogen: die Pfade als Dienste in `Classes\Kernel\Container` und ueberall hineinreichen.
 * Verworfen fuer diesen Task — die Aufrufstellen (`Classes\Api`, `Classes\Plugin`,
 * `Classes\File\Backend\FileSystem`, `Command\InstallCommand`) sind an den betroffenen Zeilen
 * nicht container-fuehrend, und sie es werden zu lassen waere ein zweiter Umbau innerhalb
 * dieses. Der statische Zugriff spiegelt `Config\Adapter::getConfig()`, das dieselbe Rolle
 * schon spielt.
 *
 * Der Preis ist benannt: globaler Zustand. Er wird dadurch ertraeglich, dass er **einmal** beim
 * Start gesetzt wird und jeder Zugriff davor wirft, statt einen falschen Wert zu liefern.
 */
final class Pfade
{
    private static ?string $projekt = null;

    /**
     * Setzt das Projektverzeichnis. Einmal, beim Start, vom Einstiegspunkt her.
     *
     * Geprueft wird, dass es das Verzeichnis gibt — sonst faellt der Fehler erst beim ersten
     * Zugriff auf eine Datei darunter an, und dann handelt die Meldung wieder von der Datei.
     */
    public static function setzen(string $projekt): void
    {
        $aufgeloest = realpath($projekt);

        if ($aufgeloest === false || !is_dir($aufgeloest)) {
            throw new \LogicException(sprintf(
                'Das Projektverzeichnis "%s" gibt es nicht. Der Einstiegspunkt (index.php, '
                .'bin/console.php) uebergibt es an Areanet\PIM\Classes\Kernel\Pfade::setzen(); '
                .'gepruefte Angabe statt geratener Pfad — siehe 007-001-0002.',
                $projekt
            ));
        }

        self::$projekt = $aufgeloest;
    }

    public static function istGesetzt(): bool
    {
        return self::$projekt !== null;
    }

    /**
     * Nur fuer Tests: den gesetzten Wert wieder vergessen.
     *
     * Ohne diese Methode koennte kein Test pruefen, dass ein Zugriff ohne `setzen()` wirft —
     * und genau das ist die Zusicherung dieser Klasse.
     */
    public static function zuruecksetzen(): void
    {
        self::$projekt = null;
    }

    public static function projekt(): string
    {
        if (self::$projekt === null) {
            throw new \LogicException(
                'Das Projektverzeichnis ist nicht gesetzt. Der Einstiegspunkt muss es dem '
                .'Framework uebergeben, bevor irgendetwas darauf zugreift: '
                .'Areanet\PIM\Classes\Kernel\Pfade::setzen(__DIR__). '
                .'Frueher wurde es aus der Lage des Frameworks gerechnet (ROOT_DIR) — das ging '
                .'still schief, sobald das Framework woanders lag (007-001-0002).'
            );
        }

        return self::$projekt;
    }

    /** Projektcode und Konfiguration des Projekts. */
    public static function custom(): string
    {
        return self::projekt() . '/custom';
    }

    /** Beschreibbare Laufzeitverzeichnisse: `cache`, `files`, `import`, `temp`. */
    public static function daten(): string
    {
        return self::projekt() . '/data';
    }

    /** Der Plugin-Slot des Projekts. */
    public static function plugins(): string
    {
        return self::projekt() . '/plugins';
    }

    /**
     * Das Verzeichnis des Frameworks selbst — die Wurzel des Pakets `areanet/contentfly`.
     *
     * Aus `__DIR__` abgeleitet, und das ist hier richtig: Diese Datei liegt in
     * `<paket>/Classes/Kernel/`, also zwei Ebenen unter der Paketwurzel. Eine Datei darf ihr
     * eigenes Paket finden — sie darf nur nicht daraus schliessen, wo das Projekt liegt.
     *
     * **Die Tiefe hat sich mit `007-001-0004` geaendert, und der Bezugspunkt mit ihr.** Vorher
     * waren es vier Ebenen und das Ergebnis war die Wurzel des Repos, weil `lib/contentfly/`
     * darin lag. Jetzt ist `lib/contentfly/` selbst das Paket: Es traegt sein eigenes
     * `composer.json`, und Composer legt es nach `vendor/areanet/contentfly/`. Zwei Ebenen
     * aufwaerts sind von dort aus dasselbe wie vorher vier von hier — der Unterschied ist, dass
     * es jetzt nicht mehr davon abhaengt, wie tief das Paket im Projekt liegt.
     */
    public static function paket(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Die Entity-Verzeichnisse, aus denen Doctrine sein Mapping liest. */
    public static function entitiesDesFrameworks(): string
    {
        return self::paket() . '/Entity';
    }

    public static function entitiesDesProjekts(): string
    {
        return self::custom() . '/Entity';
    }
}
