<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * Die Tuer ins Framework (007-001-0003).
 *
 * ── Was hier umgedreht wird ───────────────────────────────────────────────────────────
 *
 * Der Einstiegspunkt war eine Zeile:
 *
 *     require_once __DIR__.'/lib/contentfly/bootstrap-web.php';
 *
 * Und der Bootstrap lud daraufhin selbst, was er zum Laufen brauchte — den Autoloader, den
 * zweiten Autoloader, die Konfiguration des Projekts. **Ein Paket wird vom Autoloader geladen,
 * es laedt ihn nicht.** Solange `bootstrap.php` die erste Datei ist, die jemand einbindet, kann
 * der Frameworkcode gar nicht in `vendor/` liegen: Um ihn zu finden, braeuchte man den
 * Autoloader, den er selbst erst laedt.
 *
 * Jetzt laedt der Einstiegspunkt den Autoloader und ruft eine Klasse:
 *
 *     require_once __DIR__.'/vendor/autoload.php';
 *     \Areanet\PIM\Classes\Kernel\Start::web(__DIR__);
 *
 * **Damit steht in keinem Einstiegspunkt mehr ein Pfad in den Frameworkcode.** Das ist der
 * eigentliche Gewinn: Ob `lib/contentfly/` im Projekt liegt oder unter
 * `vendor/areanet/contentfly/`, sieht der Einstiegspunkt nicht mehr.
 *
 * ── Was hier geprueft wird, und warum genau hier ──────────────────────────────────────
 *
 * Drei Bedingungen, die frueher entweder gar nicht geprueft wurden oder erst weit spaeter
 * auffielen. Sie stehen hier und nicht im Bootstrap, weil beide Wege — Web und Konsole —
 * durch diese Klasse gehen und eine Pruefung an zwei Stellen auseinanderlaeuft.
 */
final class Start
{
    /**
     * Der Web-Einstieg. Kehrt nicht zurueck — der Bootstrap endet mit `$app->run()`.
     */
    public static function web(string $projekt): void
    {
        self::vorbereiten($projekt);

        require Pfade::paket() . '/lib/contentfly/bootstrap-web.php';
    }

    /**
     * Der Konsolen-Einstieg. Gibt die aufgebaute Anwendung zurueck.
     *
     * `APPCMS_CONSOLE` steuert an mehreren Stellen, dass kein Redirect und keine Session
     * passiert; gesetzt wird die Konstante hier, damit ein Aufrufer sie nicht vergessen kann.
     */
    public static function konsole(string $projekt): ApplicationInterface
    {
        if (!defined('APPCMS_CONSOLE')) {
            define('APPCMS_CONSOLE', true);
        }

        self::vorbereiten($projekt);

        /** @var ApplicationInterface $app */
        require Pfade::paket() . '/lib/contentfly/bootstrap.php';

        return $app;
    }

    /**
     * Alles, was vor dem Bootstrap feststehen muss.
     */
    private static function vorbereiten(string $projekt): void
    {
        Pfade::setzen($projekt);

        /*
         * Fuer custom/config.php, das den Fundort der .env daran haengt. Die Konstante ist der
         * Rest von ROOT_DIR, den das PROJEKT noch sieht — das Framework selbst benutzt sie
         * nicht mehr, es fragt Pfade.
         */
        if (!defined('CONTENTFLY_PROJEKT')) {
            define('CONTENTFLY_PROJEKT', Pfade::projekt());
        }

        self::keinZweiterBaum();
        self::konfigurationVorhanden();
    }

    /**
     * Ein Projekt, ein Composer-Baum (007-001-0001).
     *
     * `custom/vendor/autoload.php` wurde bis hierhin zusaetzlich geladen, und die Rangfolge
     * „Root schlaegt Projekt" war eine Zusicherung aus `006-004-0001`. Mit dem Bibliothekspaket
     * faellt die Grundlage weg: Das Framework ist dann eine Abhaengigkeit **im** Baum des
     * Projekts, es gibt keine zwei Baeume mehr, zwischen denen eine Rangfolge zu regeln waere.
     *
     * **Warum das ein Abbruch ist und keine stille Nichtbeachtung.** Ein liegengebliebenes
     * `custom/vendor/` sieht aus wie etwas, das benutzt wird. Wuerde es ab jetzt einfach nicht
     * mehr geladen, fehlte dem Projekt eine Klasse, und die Meldung handelte von dieser Klasse —
     * nicht davon, dass ein ganzer Baum nicht mehr gilt. Dasselbe Muster, gegen das
     * `000-000-0029` und `007-001-0002` gebaut sind.
     */
    private static function keinZweiterBaum(): void
    {
        $zweiter = Pfade::custom() . '/vendor/autoload.php';

        if (!is_file($zweiter)) {
            return;
        }

        self::abbrechen(
            "Es liegt noch ein zweiter Composer-Baum unter custom/vendor/.\n\n"
            ."Seit 007-001 hat ein Projekt genau EINEN Baum: Das Framework ist eine\n"
            ."Abhaengigkeit darin, kein zweiter Baum daneben. Was in custom/vendor/ liegt,\n"
            ."wird nicht mehr geladen — und das still hinzunehmen waere schlimmer als\n"
            ."abzubrechen: Die Folgemeldung handelte von einer fehlenden Klasse statt von\n"
            ."einem Baum, der nicht mehr gilt.\n\n"
            ."Zu tun: Was custom/composer.json noch braucht, in das Manifest des Projekts\n"
            ."uebernehmen, dann custom/vendor/ und custom/composer.json entfernen.\n"
            ."Siehe an_project/docs/breaking-changes.md, Abschnitt Paketgrenze."
        );
    }

    /**
     * Die Konfiguration des Projekts — gesucht an genau einer Stelle, und die wird genannt.
     *
     * Frueher war es ein nacktes `require_once` auf einen festen Pfad. Fehlte die Datei, kam
     * PHPs eigene Meldung: „Failed opening required …". Die nennt den Pfad, aber nicht, dass
     * es sich um die Konfiguration handelt und wie man zu ihr kommt.
     */
    private static function konfigurationVorhanden(): void
    {
        $konfiguration = Pfade::custom() . '/config.php';

        if (is_file($konfiguration) && is_readable($konfiguration)) {
            return;
        }

        self::abbrechen(sprintf(
            "Die Konfiguration des Projekts fehlt oder ist nicht lesbar:\n\n    %s\n\n"
            ."Contentfly erwartet sie unter custom/config.php im Projektverzeichnis.\n"
            ."Das Projektverzeichnis ist: %s\n\n"
            ."Stimmt das nicht, uebergibt der Einstiegspunkt das falsche Verzeichnis an\n"
            ."Start::web() bzw. Start::konsole().\n\n"
            ."Auf einem frischen Checkout legt `php bin/console.php appcms:install` sie an.",
            $konfiguration,
            Pfade::projekt()
        ));
    }

    /**
     * Abbruch mit einer Meldung, die in beiden Welten ankommt.
     *
     * **`STDERR` gibt es nur in der CLI.** In der Web-SAPI ist die Konstante nicht definiert,
     * und ein `fwrite(STDERR, …)` waere dort selbst ein Fehler — die Meldung ueber den Fehler
     * verursachte einen zweiten und ginge unter. (Genau das stand nach `007-001-0002` in
     * `bootstrap.php` und ist mit diesem Task korrigiert.)
     *
     * Geworfen statt `exit`: Eine Exception traegt die Meldung in das Fehlerprotokoll, das die
     * Umgebung ohnehin fuehrt, und laesst sich im Test pruefen. Ein `exit` liesse sich nicht
     * pruefen — und ein Abbruchweg, den kein Test betritt, ist ein Abbruchweg, auf den man
     * sich nicht verlassen kann.
     */
    private static function abbrechen(string $meldung): never
    {
        throw new \RuntimeException("Contentfly kann nicht starten.\n\n" . $meldung);
    }
}
