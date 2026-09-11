<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anmeldung ueber einen OIDC-Provider, geprueft am Userinfo-Endpunkt (013-005-0003).
 *
 * Der Client holt sich sein Access-Token beim Identity-Provider und zeigt es hier vor.
 * Contentfly fragt den Userinfo-Endpunkt: Antwortet der mit den Benutzerdaten, gilt das Token;
 * antwortet er mit einem Fehler, nicht.
 *
 * ── Warum der Userinfo-Weg und nicht die lokale Pruefung ──────────────────────────────
 *
 * Symfony bringt beides mit. Gemessen am 2026-09-11:
 *
 *   lokal gegen JWKS   5 Pakete, darunter web-token/jwt-library und spomky-labs/pki-framework.
 *                      Schnell, uebersteht einen Ausfall des Providers — aber ein Widerruf
 *                      wirkt erst mit dem Ablauf des Tokens.
 *   Userinfo           3 leichte Pakete. Ein Widerruf wirkt sofort — aber jede Anmeldung
 *                      haengt am Provider.
 *
 * Entschieden: Userinfo. **Was die Abwaegung relativiert und dazugehoert:** Contentfly stellt
 * nach der Anmeldung ein EIGENES Token aus (013-003). Der OIDC-Token wird genau einmal geprueft,
 * beim Login; danach zaehlt nur noch das eigene. Der Widerrufsvorteil betrifft damit nur das
 * Fenster zwischen Widerruf und dem einen Anmeldeversuch — und der Ausfall-Nachteil ebenso nur
 * die Anmeldung, nicht die laufende Sitzung.
 *
 * ── Warum nicht Symfonys OidcUserInfoTokenHandler ─────────────────────────────────────
 *
 * Der ist ein `AccessTokenHandlerInterface` und liefert ein `UserBadge` — also eine Kennung und
 * sonst nichts. **Die Gruppen waeren damit weg**, und genau die braucht der Vertrag aus
 * `013-004`, damit `Gruppenabbildung` etwas abzubilden hat. Ein Wrapper muesste den Endpunkt ein
 * zweites Mal fragen. Der eigene Aufruf ist kuerzer als dieser Umweg.
 *
 * ── Jeder Fehlschlag sieht gleich aus ─────────────────────────────────────────────────
 *
 * Abgelehntes Token, Antwort ohne die erwartete Kennung, Provider nicht erreichbar — alles
 * `null`.
 */
final class OidcProvider implements Anmeldeprovider
{
    /**
     * @param array{endpunkt: string, kennung_claim: string, gruppen_claim: string} $einstellungen
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $einstellungen,
    ) {
    }

    public static function ausKonfiguration(): self
    {
        $config = Adapter::getConfig();

        return new self(
            HttpClient::create(array('timeout' => 5)),
            array(
                'endpunkt'      => (string) $config->SECURITY_OIDC_USERINFO_ENDPOINT,
                'kennung_claim' => (string) $config->SECURITY_OIDC_KENNUNG_CLAIM,
                'gruppen_claim' => (string) $config->SECURITY_OIDC_GRUPPEN_CLAIM,
            )
        );
    }

    public function pruefen(Request $request): ?Fremdkennung
    {
        $token = $this->token($request);

        if ($token === null || $this->einstellungen['endpunkt'] === '') {
            return null;
        }

        try {
            $antwort = $this->client->request('GET', $this->einstellungen['endpunkt'], array(
                'headers' => array('Authorization' => 'Bearer '.$token),
            ));

            /*
             * Der Statuscode wird AUSDRUECKLICH geprueft.
             *
             * `toArray()` wirft bei 4xx und 5xx zwar von sich aus — aber nur, solange niemand
             * `throw: false` setzt. Sich auf eine Vorgabe zu verlassen, die eine Option
             * abschalten kann, ist bei einer Anmeldung die falsche Art von Sparsamkeit.
             */
            if ($antwort->getStatusCode() !== 200) {
                return null;
            }

            $daten = $antwort->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $kennung = $daten[$this->einstellungen['kennung_claim']] ?? null;

        if (!is_string($kennung) && !is_int($kennung)) {
            return null;
        }

        $kennung = (string) $kennung;

        if (trim($kennung) === '') {
            return null;
        }

        return new Fremdkennung($kennung, $this->gruppen($daten));
    }

    /**
     * Das Access-Token aus dem Request.
     *
     * `accessToken` ist der sprechende Name; `pass` wird zusaetzlich gelesen, weil ein Client,
     * der schon eine Anmeldemaske gegen `/auth/login` schickt, dasselbe Feld benutzen kann wie
     * fuer ein Passwort — und die Anmeldebremse (013-001-0003) greift ohnehin auf denselben
     * Request.
     */
    private function token(Request $request): ?string
    {
        $daten = $request->request->all();

        foreach (array('accessToken', 'pass') as $feld) {
            $wert = $daten[$feld] ?? null;

            if (is_string($wert) && trim($wert) !== '') {
                return $wert;
            }
        }

        return null;
    }

    /**
     * Was der Provider an Gruppen liefert — unveraendert.
     *
     * @param array<string, mixed> $daten
     * @return list<string>
     */
    private function gruppen(array $daten): array
    {
        $feld = $this->einstellungen['gruppen_claim'];

        if ($feld === '' || !isset($daten[$feld]) || !is_array($daten[$feld])) {
            return array();
        }

        $gruppen = array();

        foreach ($daten[$feld] as $wert) {
            if (is_string($wert) && $wert !== '') {
                $gruppen[] = $wert;
            }
        }

        return $gruppen;
    }
}
