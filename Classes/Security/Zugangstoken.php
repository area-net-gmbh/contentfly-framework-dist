<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Stellt Access-JWT aus und legt fest, was darin steht (013-003-0001).
 *
 * DER CLAIM-SATZ STEHT HIER UND NUR HIER. Bis zu diesem Task konnte der `Tokenhandler` JWT
 * pruefen, aber niemand stellte welche aus — es gab also auch keinen Satz, gegen den man
 * pruefen konnte. Fuenf Claims, mehr nicht:
 *
 *   sub   die Kennung des Benutzers
 *   iss   der Ausgeber, damit ein Token einer fremden Anwendung nicht hier gilt
 *   iat   wann es ausgestellt wurde
 *   exp   wann es verfaellt
 *   jti   die Kennung dieses einen Tokens, fuer den Widerruf in 013-003-0003
 *
 * WAS BEWUSST NICHT DRINSTEHT: Rollen, Gruppen, Berechtigungen. Sie koennen sich aendern,
 * waehrend der Token gilt — stuenden sie im Token, wirkte eine Rechteaenderung erst nach dessen
 * Ablauf. Das ist der klassische Fehler beim Umstieg auf zustandslose Tokens, und er faellt
 * erst auf, wenn jemandem ein Recht entzogen wird und es nicht wirkt.
 *
 * Der Benutzer wird deshalb bei jedem Request aus `pim_user` geladen (siehe `Benutzerlader`).
 * Das ist eine Abfrage — die auf `pim_token`, samt Schreibzugriff bei jedem Aufruf, faellt
 * dafuer weg.
 *
 * KURZLEBIG IST DIE GANZE SICHERHEITSLEISTUNG. Ein zustandsloses Token laesst sich nicht
 * zurueckrufen, solange es gilt; je kuerzer es gilt, desto kleiner das Fenster. Erneuert wird
 * ueber das Refresh-Token (`013-003-0002`).
 */
final class Zugangstoken
{
    /**
     * Der Ausgeber.
     *
     * Eine Konstante und kein Konfigurationsfeld: Der Wert soll ueber alle Installationen
     * gleich sein, weil er die Anwendung benennt und nicht die Instanz. Wer zwei Instanzen
     * trennen will, gibt ihnen verschiedene Geheimnisse — das trennt wirksam, ein abweichender
     * `iss` bei gleichem Geheimnis nicht.
     */
    public const AUSGEBER = 'contentfly';

    /** Das Verfahren. Ein Token mit einem anderen `alg` wird abgewiesen. */
    public const VERFAHREN = 'HS256';

    /**
     * Die Claims, die ein ausgestelltes Token traegt — und gegen die geprueft wird.
     *
     * Als Liste da, damit ein Test sie gegen ein echtes Token halten kann und auffaellt, wenn
     * jemand einen sechsten hinzufuegt.
     */
    public const CLAIMS = array('sub', 'iss', 'iat', 'exp', 'jti');

    /**
     * Stellt ein Access-JWT aus.
     *
     * @return array{token: string, jti: string, exp: int}
     */
    public static function ausstellen(UserInterface $benutzer, ?int $lebensdauer = null): array
    {
        $geheimnis = self::geheimnis();
        $jetzt     = time();
        $exp       = $jetzt + ($lebensdauer ?? self::lebensdauer());
        $jti       = bin2hex(random_bytes(16));

        $claims = array(
            'sub' => $benutzer->getUserIdentifier(),
            'iss' => self::AUSGEBER,
            'iat' => $jetzt,
            'exp' => $exp,
            'jti' => $jti,
        );

        return array(
            // Der vierte Parameter setzt `kid` im Header — die Handhabe fuer den
            // Schluesselwechsel (013-003-0004).
            'token' => JWT::encode($claims, $geheimnis, self::VERFAHREN, self::kennung()),
            'jti'   => $jti,
            'exp'   => $exp,
        );
    }

    /**
     * Ob diese Installation ueberhaupt JWT ausstellen kann.
     *
     * Getrennt von `geheimnis()`, damit der Aufrufer eine verstaendliche Antwort geben kann,
     * statt eine Ausnahme durchschlagen zu lassen.
     */
    public static function eingerichtet(): bool
    {
        $geheimnis = Adapter::getConfig()->SECURITY_JWT_SECRET;

        return is_string($geheimnis) && $geheimnis !== '';
    }

    /**
     * Das Signaturgeheimnis.
     *
     * OHNE WERT WIRD GEWORFEN, nicht mit einem Ersatz weitergemacht. Ein im Code hinterlegter
     * Standardschluessel waere kein Schluessel; und eine Anwendung, die stillschweigend etwas
     * anderes tut als das Verlangte, ist schlimmer als eine, die stehenbleibt.
     */
    public static function geheimnis(): string
    {
        if (!self::eingerichtet()) {
            throw new \RuntimeException(
                'SECURITY_JWT_SECRET ist nicht gesetzt. Ohne Signaturgeheimnis lassen sich keine '
                .'JWT ausstellen; siehe custom/config.php.'
            );
        }

        return (string) Adapter::getConfig()->SECURITY_JWT_SECRET;
    }

    /** Die Kennung des aktuellen Schluessels — sie steht als `kid` im Token-Header. */
    public static function kennung(): string
    {
        $wert = (string) Adapter::getConfig()->SECURITY_JWT_KEY_ID;

        return $wert !== '' ? $wert : 'k1';
    }

    /**
     * Alle Schluessel, gegen die geprueft wird — nach Kennung.
     *
     * DER AKTUELLE UND, WENN GESETZT, DER VORHERIGE. Das ist der ganze Schluesselwechsel:
     * Signiert wird mit dem aktuellen, angenommen werden beide. Wer den Wechsel macht, schiebt
     * den bisherigen Wert nach `SECURITY_JWT_SECRET_PREVIOUS` und legt einen neuen an — niemand
     * muss sich neu anmelden, und nach Ablauf des laengsten Access-JWT kann der alte weg.
     *
     * ZWEI GLEICHE KENNUNGEN WERDEN ABGEWIESEN. Sonst ueberschriebe die eine die andere im
     * Array, und die Anwendung akzeptierte stillschweigend nur einen der beiden Schluessel —
     * mitten in einem Wechsel der schlechteste Zeitpunkt fuer eine stille Ueberraschung.
     *
     * @return array<string, Key>
     */
    public static function pruefschluessel(): array
    {
        $schluessel = array(self::kennung() => new Key(self::geheimnis(), self::VERFAHREN));

        $vorher        = Adapter::getConfig()->SECURITY_JWT_SECRET_PREVIOUS;
        $vorherKennung = Adapter::getConfig()->SECURITY_JWT_KEY_ID_PREVIOUS;

        if (!is_string($vorher) || $vorher === '') {
            return $schluessel;
        }

        if (!is_string($vorherKennung) || $vorherKennung === '') {
            throw new \RuntimeException(
                'SECURITY_JWT_SECRET_PREVIOUS ist gesetzt, SECURITY_JWT_KEY_ID_PREVIOUS nicht. '
                .'Ein Schluessel ohne Kennung laesst sich keinem Token zuordnen.'
            );
        }

        if ($vorherKennung === self::kennung()) {
            throw new \RuntimeException(
                'SECURITY_JWT_KEY_ID und SECURITY_JWT_KEY_ID_PREVIOUS sind gleich ("'
                .$vorherKennung.'"). Waehrend eines Schluesselwechsels muessen sie sich '
                .'unterscheiden, sonst gilt nur einer der beiden Schluessel.'
            );
        }

        $schluessel[$vorherKennung] = new Key($vorher, self::VERFAHREN);

        return $schluessel;
    }

    /** Die Lebensdauer in Sekunden, aus der Konfiguration. */
    public static function lebensdauer(): int
    {
        $wert = (int) Adapter::getConfig()->SECURITY_JWT_TTL;

        return $wert > 0 ? $wert : 900;
    }
}
