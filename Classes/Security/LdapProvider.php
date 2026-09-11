<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Anmeldung gegen ein LDAP oder Active Directory (013-005-0001).
 *
 * Er erfuellt den Vertrag aus `013-004-0001` und sonst nichts: Er prueft gegen das Verzeichnis
 * und gibt eine `Fremdkennung` zurueck. Benutzer anlegen, Gruppen abbilden und Token ausstellen
 * macht das Framework.
 *
 * ── SUCHEN, DANN BINDEN — und warum nicht der direkte Bind ────────────────────────────
 *
 * Der direkte Weg setzt den DN aus der Kennung zusammen (`uid=<kennung>,ou=…`) und bindet damit.
 * Er kommt ohne Dienstkonto aus und ist deshalb verlockend. Er funktioniert aber nur, solange
 * alle Benutzer flach in einer OU liegen — und im Active Directory tun sie das nicht: Dort
 * haengen sie in verschachtelten Organisationseinheiten, und angemeldet wird mit
 * `sAMAccountName`, der im DN ueberhaupt nicht vorkommt. Ein Framework, das nur den einfachen
 * Fall kann, ist fuer den Fall, um den es hier geht, nutzlos.
 *
 * Also: mit dem Dienstkonto binden, den Benutzer suchen, dann ein zweites Mal mit SEINEM DN und
 * SEINEM Passwort binden. Wo das Verzeichnis eine anonyme Suche erlaubt, bleibt das Dienstkonto
 * leer.
 *
 * ── Ein leeres Passwort wird abgewiesen, bevor irgendetwas passiert ───────────────────
 *
 * DAS IST KEINE HOEFLICHKEIT, SONDERN DIE WICHTIGSTE ZEILE HIER. LDAP kennt den
 * „unauthenticated bind": Ein Bind mit gueltigem DN und LEEREM Passwort gilt als erfolgreich —
 * er bedeutet „ich will mich nicht anmelden", nicht „das Passwort stimmt". Wer das Ergebnis
 * dieses Binds als Anmeldung liest, laesst jeden herein, dessen Kennung er kennt. Es ist einer
 * der aeltesten Fehler in LDAP-Anbindungen.
 *
 * ── Jeder Fehlschlag sieht gleich aus ─────────────────────────────────────────────────
 *
 * Kennung unbekannt, Passwort falsch, Verzeichnis nicht erreichbar, Dienstkonto abgelaufen —
 * alles `null`. Der Aufrufer erfaehrt nur, dass es nicht gereicht hat; ob das Verzeichnis
 * antwortet, geht ihn nichts an.
 */
final class LdapProvider implements Anmeldeprovider, Bestandspruefung
{
    /**
     * @param array{base_dn: string, filter: string, gruppen_attribut: string,
     *              search_dn: ?string, search_password: ?string} $einstellungen
     */
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly array $einstellungen,
    ) {
    }

    /**
     * Baut den Provider aus der Konfiguration.
     *
     * Getrennt vom Konstruktor, damit der Provider fuer Tests einen `LdapInterface` bekommen
     * kann, ohne dass ein Verzeichnis laeuft.
     */
    public static function ausKonfiguration(): self
    {
        $config = Adapter::getConfig();

        return new self(
            Ldap::create('ext_ldap', array(
                'host'       => (string) $config->SECURITY_LDAP_HOST,
                'port'       => (int) $config->SECURITY_LDAP_PORT,
                'encryption' => (string) $config->SECURITY_LDAP_ENCRYPTION,
            )),
            array(
                'base_dn'          => (string) $config->SECURITY_LDAP_BASE_DN,
                'filter'           => (string) $config->SECURITY_LDAP_FILTER,
                'gruppen_attribut' => (string) $config->SECURITY_LDAP_GRUPPEN_ATTRIBUT,
                'search_dn'        => $config->SECURITY_LDAP_SEARCH_DN,
                'search_password'  => $config->SECURITY_LDAP_SEARCH_PASSWORD,
            )
        );
    }

    public function pruefen(Request $request): ?Fremdkennung
    {
        $daten   = $request->request->all();
        $kennung = $daten['alias'] ?? null;
        $passwort = $daten['pass'] ?? null;

        if (!is_string($kennung) || trim($kennung) === '') {
            return null;
        }

        /*
         * SIEHE KLASSENKOMMENTAR: Ein leeres Passwort ist ein unauthenticated bind und gilt im
         * Verzeichnis als erfolgreich. Hier ist es eine Ablehnung.
         */
        if (!is_string($passwort) || $passwort === '') {
            return null;
        }

        try {
            $eintrag = $this->suchen($kennung);

            if (!$eintrag instanceof Entry) {
                return null;
            }

            // Der zweite Bind — mit dem DN aus dem Verzeichnis, nicht mit einem gebauten.
            $this->ldap->bind($eintrag->getDn(), $passwort);

            return new Fremdkennung($kennung, $this->gruppen($eintrag));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Der erste Bind und die Suche.
     *
     * Genau EIN Treffer zaehlt. Zwei Treffer heissen, dass der Filter nicht eindeutig ist — und
     * dann zu raten, welcher gemeint war, waere die schlechteste aller Antworten.
     */
    private function suchen(string $kennung): ?Entry
    {
        $dienstkonto = $this->einstellungen['search_dn'];

        if (is_string($dienstkonto) && $dienstkonto !== '') {
            $this->ldap->bind($dienstkonto, (string) $this->einstellungen['search_password']);
        } else {
            // Anonyme Suche, wo das Verzeichnis sie erlaubt.
            $this->ldap->bind();
        }

        /*
         * MASKIERT, UND ZWAR ALS FILTER.
         *
         * Ohne `escape()` traegt eine Kennung wie `*` oder `admin)(|(objectClass=*` den Filter
         * um — LDAP-Injection, dasselbe Muster wie SQL-Injection und genauso alt.
         */
        $filter = str_replace(
            '{kennung}',
            $this->ldap->escape($kennung, '', LdapInterface::ESCAPE_FILTER),
            (string) $this->einstellungen['filter']
        );

        $treffer = $this->ldap->query((string) $this->einstellungen['base_dn'], $filter)->execute();

        if (count($treffer) !== 1) {
            return null;
        }

        $eintrag = $treffer[0];

        return $eintrag instanceof Entry ? $eintrag : null;
    }

    /**
     * Kennt das Verzeichnis diese Kennung noch? (013-005-0002)
     *
     * Ohne Passwort — es geht nicht um eine Anmeldung, sondern um den Bestand. Gebunden wird
     * nur mit dem Dienstkonto, gesucht wird mit demselben Filter wie bei der Anmeldung.
     *
     * `null` HEISST „WEISS ICH GERADE NICHT". Jede Ausnahme endet hier, und der Abgleich fasst
     * dann niemanden an. Ein nicht erreichbares Verzeichnis darf nicht wie ein geloeschter
     * Benutzer aussehen — sonst sperrt ein Netzwerkfehler die ganze Belegschaft aus.
     */
    public function kenntKennung(string $kennung): ?bool
    {
        if (trim($kennung) === '') {
            return false;
        }

        try {
            return $this->suchen($kennung) instanceof Entry;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Was das Verzeichnis an Gruppen sagt — unveraendert.
     *
     * Abgebildet wird es von `Gruppenabbildung` (013-004-0003). Hier etwas umzuschreiben hiesse,
     * die Abbildung an zwei Stellen zu haben.
     *
     * @return list<string>
     */
    private function gruppen(Entry $eintrag): array
    {
        $werte = $eintrag->getAttribute((string) $this->einstellungen['gruppen_attribut'], false);

        if (!is_array($werte)) {
            return array();
        }

        $gruppen = array();

        foreach ($werte as $wert) {
            if (is_string($wert) && $wert !== '') {
                $gruppen[] = $wert;
            }
        }

        return $gruppen;
    }
}
