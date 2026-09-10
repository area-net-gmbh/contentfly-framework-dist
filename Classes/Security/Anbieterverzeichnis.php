<?php
namespace Areanet\PIM\Classes\Security;

/**
 * Die Allowlist der Anmeldeprovider (013-004-0001).
 *
 * WARUM ES SIE GIBT. Bis hierhin kam der Klassenname als Request-Parameter `loginManager` und
 * wurde zu `Custom\Classes\<Name>` aufgeloest. Der Praefix und eine `instanceof`-Pruefung
 * begrenzten den Schaden — aber die Auswahl lag beim Aufrufer. **Welche Klasse eine Anwendung
 * instanziiert, ist eine Entscheidung des Betreibers.**
 *
 * Der Unterschied ist nicht kosmetisch: Aus „der Aufrufer sagt, was geladen wird" wird „der
 * Aufrufer waehlt aus dem, was der Betreiber freigegeben hat". Ein Name, den niemand
 * eingetragen hat, existiert nicht — und ein Klassenname ist so ein Name.
 *
 * DIE EINTRAEGE SIND FAUL. Ein Provider baut womoeglich eine Verbindung zu einem Fremdsystem
 * auf; das darf nicht bei jedem Request passieren, sondern erst, wenn ihn jemand anfragt.
 * `eintragen()` nimmt deshalb auch eine Closure.
 */
final class Anbieterverzeichnis
{
    /** @var array<string, Anmeldeprovider|callable(): Anmeldeprovider> */
    private array $eintraege = array();

    /** @var array<string, Anmeldeprovider> */
    private array $aufgebaut = array();

    /**
     * @param Anmeldeprovider|callable(): Anmeldeprovider $anbieter
     */
    public function eintragen(string $name, $anbieter): void
    {
        $name = $this->normalisieren($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Ein Anmeldeprovider braucht einen Namen.');
        }

        /*
         * EIN ZWEITER EINTRAG UNTER DEMSELBEN NAMEN WIRD ABGEWIESEN.
         *
         * Stillschweigend zu ueberschreiben hiesse, dass die Reihenfolge zweier Zeilen in
         * `custom/app.php` darueber entscheidet, gegen welches Fremdsystem geprueft wird. Das
         * faellt niemandem auf, bis es das Falsche tut.
         */
        if (isset($this->eintraege[$name])) {
            throw new \LogicException(
                'Ein Anmeldeprovider namens "'.$name.'" ist bereits eingetragen. Namen muessen '
                .'eindeutig sein, sonst entscheidet die Reihenfolge der Registrierung.'
            );
        }

        if (!$anbieter instanceof Anmeldeprovider && !is_callable($anbieter)) {
            throw new \InvalidArgumentException(
                'Ein Anmeldeprovider ist entweder eine Anmeldeprovider-Instanz oder eine '
                .'Closure, die eine liefert.'
            );
        }

        $this->eintraege[$name] = $anbieter;
    }

    public function hat(?string $name): bool
    {
        return $name !== null && isset($this->eintraege[$this->normalisieren($name)]);
    }

    /**
     * Der Provider zu diesem Namen — oder null.
     *
     * Ein unbekannter Name liefert null und keine Ausnahme: Er kommt aus dem Request, und ein
     * Aufrufer, der raet, soll nichts anderes zu sehen bekommen als jeder andere Fehlschlag.
     */
    public function holen(?string $name): ?Anmeldeprovider
    {
        if (!$this->hat($name)) {
            return null;
        }

        $name = $this->normalisieren((string) $name);

        if (!isset($this->aufgebaut[$name])) {
            $eintrag = $this->eintraege[$name];
            $anbieter = $eintrag instanceof Anmeldeprovider ? $eintrag : $eintrag();

            if (!$anbieter instanceof Anmeldeprovider) {
                throw new \LogicException(
                    'Die Closure fuer den Anmeldeprovider "'.$name.'" liefert keinen '
                    .Anmeldeprovider::class.'.'
                );
            }

            $this->aufgebaut[$name] = $anbieter;
        }

        return $this->aufgebaut[$name];
    }

    /**
     * Die eingetragenen Namen.
     *
     * Fuer Diagnose und Tests — NICHT fuer eine API-Antwort: Welche Fremdsysteme eine
     * Installation kennt, geht einen unangemeldeten Aufrufer nichts an.
     *
     * @return list<string>
     */
    public function namen(): array
    {
        return array_keys($this->eintraege);
    }

    /**
     * Gross- und Kleinschreibung soll nicht entscheiden.
     *
     * Ein Projekt traegt `LDAP` ein, ein Client schickt `ldap` — dass daraus zwei verschiedene
     * Dinge werden, waere eine Falle ohne jeden Nutzen.
     */
    private function normalisieren(string $name): string
    {
        return strtolower(trim($name));
    }
}
