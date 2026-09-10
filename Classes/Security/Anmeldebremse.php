<?php
namespace Areanet\PIM\Classes\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Bremst Anmeldeversuche — pro Kennung und pro IP, mit ansteigender Verzoegerung (013-001-0003).
 *
 * WAS VORHER DA WAR, WAR KEINE BREMSE. `AuthController::CHECK_LOGIN_INTERVAL` war eine
 * `false`-Konstante, also fest aus. Selbst eingeschaltet waere es ein 60-Sekunden-Abstand
 * **pro Benutzer** gewesen, gemessen am zuletzt ausgestellten Token — gegen das Raten ueber
 * viele Konten hinweg wirkungslos, und gegen das Raten vieler Passwoerter zu EINEM Konto nur
 * dann, wenn zwischendurch nie ein Token entstand. Ein Angreifer, der nie richtig raet, stellt
 * nie einen Token aus.
 *
 * ZWEI ACHSEN, WEIL EINE JEDE FUER SICH UMGEHBAR IST:
 *
 *   pro Kennung  faengt den Angriff auf EIN Konto, auch wenn er von vielen Adressen kommt
 *   pro IP       faengt das Durchprobieren VIELER Kennungen von einer Adresse
 *
 * ANSTEIGENDE VERZOEGERUNG DURCH GESTAFFELTE FENSTER. Statt einer Grenze stehen drei
 * uebereinander: eine Minute, eine Viertelstunde, eine Stunde. Wer die erste reisst, wartet
 * rund eine Minute; wer weitermacht, wartet die Viertelstunde; wer dann noch weitermacht, die
 * Stunde. Die Wartezeit waechst also mit der Hartnaeckigkeit, ohne dass irgendwo ein Zaehler
 * fuer „Strafstufen" gefuehrt werden muesste.
 *
 * NUR FEHLVERSUCHE ZAEHLEN. Eine gelungene Anmeldung verbraucht nichts und setzt den Zaehler
 * der Kennung ausserdem zurueck. Das ist der Unterschied zwischen einer Bremse und einer
 * Nutzungsobergrenze: Eine Anwendung, die auch richtige Anmeldungen zaehlt, sperrt irgendwann
 * genau die Benutzer aus, die alles richtig machen.
 *
 * DER ZAEHLER DER IP WIRD NICHT ZURUECKGESETZT. Sonst genuegte dem Angreifer ein einziges
 * gueltiges Konto — sein eigenes —, um sich nach jedem Block wieder freizuschalten.
 *
 * DIE PRUEFUNG VERBRAUCHT NICHTS. `consume(0)` liest den Stand, ohne ihn zu veraendern; das
 * gemeldete `isAccepted` ist dabei fest `true`, weshalb hier die verbleibenden Token gezaehlt
 * werden und nicht diese Kennzeichnung. Wuerde die Pruefung selbst verbrauchen, verlaengerte
 * jeder abgewiesene Versuch die Sperre — der Block liefe nie ab, und ein Angreifer koennte ein
 * fremdes Konto dauerhaft sperren, indem er gegen die geschlossene Tuer weiterlaeuft.
 *
 * KEIN LOCK. `symfony/lock` liegt nicht im Baum; zwei gleichzeitige Fehlversuche koennen sich
 * daher im ungluecklichen Fall einen Zaehlschritt teilen. Das verschiebt die Grenze um
 * Einzelschritte und nicht um Groessenordnungen — ein Lock pro Anmeldeversuch waere ein
 * Angriffspunkt fuer sich.
 *
 * DER SCHLUESSEL IST EIN HASH, und die Kennung wird vorher kleingeschrieben: `Admin` und
 * `admin` teilen sich einen Eimer, sonst waere die Grenze durch Gross- und Kleinschreibung zu
 * vervielfachen.
 */
final class Anmeldebremse
{
    /**
     * Die Staffel pro Kennung.
     *
     * Fuenf Fehlversuche in der Minute sind grosszuegig fuer einen Menschen, der sich vertippt,
     * und eng fuer ein Skript.
     */
    private const STUFEN_KENNUNG = array(
        array('limit' => 5,  'interval' => '1 minute'),
        array('limit' => 20, 'interval' => '15 minutes'),
        array('limit' => 50, 'interval' => '1 hour'),
    );

    /**
     * Die Staffel pro IP, weiter gefasst.
     *
     * Hinter einer Adresse koennen viele Benutzer sitzen — ein Buero mit einem Anschluss, ein
     * Mobilfunk-Gateway. Die Grenze muss deshalb ueber der liegen, die eine einzelne Kennung
     * bekommt, sonst bremst sie die Nachbarn statt den Angreifer.
     */
    private const STUFEN_IP = array(
        array('limit' => 20,  'interval' => '1 minute'),
        array('limit' => 60,  'interval' => '15 minutes'),
        array('limit' => 200, 'interval' => '1 hour'),
    );

    private CacheStorage $speicher;

    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->speicher = new CacheStorage($pool);
    }

    /**
     * Wie lange dieser Versuch noch warten muss — oder null, wenn er durchgelassen wird.
     *
     * Geliefert wird die LAENGSTE Wartezeit ueber alle gerissenen Fenster hinweg. Genau darin
     * steckt die ansteigende Verzoegerung: Wer nur die Minutengrenze reisst, wartet eine
     * Minute; wer auch die Viertelstundengrenze reisst, wartet die Viertelstunde, weil deren
     * Fenster spaeter ablaeuft.
     *
     * DESHALB WIRD JEDES FENSTER EINZELN GELESEN und nicht ueber einen `CompoundLimiter`. Der
     * meldet den knappsten Stand zurueck — nach 20 Fehlversuchen ist das die Minutengrenze mit
     * ihren -15 Token, und deren Wartezeit ist nie laenger als eine Minute. Gemessen: Die
     * zweite Stufe kam damit auf dieselben 60 Sekunden wie die erste, die Staffel war wirkungslos.
     */
    public function wartezeit(?string $kennung, ?string $ip): ?int
    {
        $wartezeit = null;

        foreach ($this->begrenzer($kennung, $ip) as $begrenzer) {
            $stand = $begrenzer->consume(0);

            if ($stand->getRemainingTokens() >= 1) {
                continue;
            }

            $sekunden  = max(1, $stand->getRetryAfter()->getTimestamp() - time());
            $wartezeit = max($wartezeit ?? 0, $sekunden);
        }

        return $wartezeit;
    }

    /**
     * Zaehlt einen Fehlversuch in jedem Fenster beider Achsen.
     */
    public function fehlversuch(?string $kennung, ?string $ip): void
    {
        foreach ($this->begrenzer($kennung, $ip) as $begrenzer) {
            $begrenzer->consume(1);
        }
    }

    /**
     * Setzt die Fenster EINER Kennung zurueck — nach einer gelungenen Anmeldung.
     *
     * Die Achse der IP bleibt bewusst stehen, siehe Klassenkommentar.
     */
    public function entsperren(?string $kennung): void
    {
        foreach ($this->begrenzer($kennung, null) as $begrenzer) {
            $begrenzer->reset();
        }
    }

    /**
     * Alle Fenster, die fuer diesen Versuch gelten — beide Achsen flach hintereinander.
     *
     * Eine Achse ohne Wert faellt weg: Eine Anmeldung ueber einen LoginManager bringt keine
     * Kennung mit, und `getClientIp()` kann null liefern.
     *
     * @return list<LimiterInterface>
     */
    private function begrenzer(?string $kennung, ?string $ip): array
    {
        $liste = array();

        if (($schluessel = $this->schluessel($kennung)) !== null) {
            $liste = array_merge($liste, $this->staffel('kennung', $schluessel, self::STUFEN_KENNUNG));
        }

        if (($schluessel = $this->schluessel($ip)) !== null) {
            $liste = array_merge($liste, $this->staffel('ip', $schluessel, self::STUFEN_IP));
        }

        return $liste;
    }

    private function schluessel(?string $wert): ?string
    {
        $wert = trim((string) $wert);

        if ($wert === '') {
            return null;
        }

        return hash('sha256', mb_strtolower($wert));
    }

    /**
     * Baut die drei uebereinanderliegenden Fenster einer Achse.
     *
     * @param  list<array{limit: int, interval: string}> $stufen
     * @return list<LimiterInterface>
     */
    private function staffel(string $bereich, string $schluessel, array $stufen): array
    {
        $begrenzer = array();

        foreach ($stufen as $nummer => $stufe) {
            $fabrik = new RateLimiterFactory(
                array(
                    'id'       => 'anmeldung-'.$bereich.'-'.$nummer,
                    'policy'   => 'sliding_window',
                    'limit'    => $stufe['limit'],
                    'interval' => $stufe['interval'],
                ),
                $this->speicher
            );

            $begrenzer[] = $fabrik->create($schluessel);
        }

        return $begrenzer;
    }
}
