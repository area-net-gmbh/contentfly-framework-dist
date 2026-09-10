<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Sagt HttpFoundation, hinter welchen Proxies die Anwendung steht (013-001-0003).
 *
 * BIS DAHIN WURDE `setTrustedProxies()` NIRGENDS GERUFEN — im ganzen Baum nicht. Das war
 * folgenlos, solange niemand die IP des Aufrufers auswertete. Mit dem Rate-Limiting pro IP
 * wird es zum Kernproblem: `Request::getClientIp()` liefert ohne vertraute Proxies die Adresse
 * des naechsten Hops. Steht ein Loadbalancer davor, ist das SEINE Adresse — die Bremse
 * traefe ihn und damit alle Benutzer dahinter, waehrend der Angreifer ungebremst weiterraet.
 *
 * OHNE KONFIGURATION AENDERT SICH NICHTS. `APP_TRUSTED_PROXIES` ist leer vorbelegt; dann wird
 * `setTrustedProxies()` gar nicht erst gerufen und die Anwendung verhaelt sich wie bisher. Wer
 * keine Proxies konfiguriert, betreibt sie direkt — dann stimmt die IP ohnehin.
 *
 * DIE VORGABE IST DIE ENGE: Vertraut wird nur den X-Forwarded-*-Headern. Ein `Forwarded`-Header
 * nach RFC 7239 wird erst gelesen, wenn `APP_TRUSTED_HEADERS` ausdruecklich darauf steht — er
 * transportiert dieselbe Angabe, und beide gleichzeitig zu glauben heisst, dem Aufrufer die
 * Wahl zu lassen, welche gilt.
 *
 * EIN UNBEKANNTER NAME WIRD ABGEWIESEN, nicht stillschweigend auf die Vorgabe zurueckgefuehrt.
 * Ein Tippfehler in `APP_TRUSTED_HEADERS` waere sonst eine Konfiguration, die zu wirken scheint
 * und nicht wirkt — dieselbe Falle wie beim entfallenen Cache-Treiber `apc` (010-002-0002).
 */
final class VertrauteProxies
{
    /**
     * Die erlaubten Werte von `APP_TRUSTED_HEADERS`.
     *
     * `x-forwarded` deckt die vier Angaben ab, die ein ueblicher Reverse Proxy setzt; `forwarded`
     * ist der standardisierte Einzelheader aus RFC 7239.
     */
    public const HEADERSAETZE = array(
        'x-forwarded' => Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        'forwarded'   => Request::HEADER_FORWARDED,
    );

    /**
     * Setzt die vertrauten Proxies, sofern welche konfiguriert sind.
     *
     * @param  array<int|string, string>|string|null $proxies
     * @return bool  true, wenn gesetzt wurde; false, wenn nichts konfiguriert ist
     */
    public static function anwenden($proxies, string $headerSatz): bool
    {
        $liste = self::liste($proxies);

        if ($liste === array()) {
            return false;
        }

        Request::setTrustedProxies($liste, self::headerSatz($headerSatz));

        return true;
    }

    /**
     * Bringt die Angabe aus der Konfiguration auf eine Liste.
     *
     * Erlaubt ist beides: ein Array von Adressen oder Netzen, oder eine Zeichenkette mit
     * Kommas. Die zweite Form gibt es, weil eine solche Angabe oft aus einer Umgebungsvariablen
     * kommt und dort nur als Zeichenkette existieren kann.
     *
     * `REMOTE_ADDR` ist ein Sonderwert von HttpFoundation und bleibt unangetastet: Er bedeutet
     * „der unmittelbare Absender, wer immer das ist" und ist die richtige Angabe fuer eine
     * Anwendung, die nur ueber einen Proxy erreichbar ist, dessen Adresse wechselt.
     *
     * @param  array<int|string, string>|string|null $proxies
     * @return list<string>
     */
    public static function liste($proxies): array
    {
        if (is_array($proxies)) {
            $roh = $proxies;
        } elseif (is_string($proxies)) {
            $roh = preg_split('/\s*,\s*/', $proxies, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        } else {
            return array();
        }

        $liste = array();
        foreach ($roh as $eintrag) {
            $eintrag = trim((string) $eintrag);
            if ($eintrag !== '') {
                $liste[] = $eintrag;
            }
        }

        return $liste;
    }

    /**
     * Uebersetzt `APP_TRUSTED_HEADERS` in die Bitmaske von HttpFoundation.
     */
    public static function headerSatz(string $name): int
    {
        $name = strtolower(trim($name));

        if (!isset(self::HEADERSAETZE[$name])) {
            throw new \RuntimeException(sprintf(
                'APP_TRUSTED_HEADERS = "%s" ist unbekannt. Erlaubt sind: %s.',
                $name,
                implode(', ', array_keys(self::HEADERSAETZE))
            ));
        }

        return self::HEADERSAETZE[$name];
    }
}
