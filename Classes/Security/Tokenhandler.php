<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\RevokedToken;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Verzweigt nach der Form des Tokens (013-002-0003).
 *
 * SYMFONY ERLAUBT GENAU EINEN `token_handler` je Firewall und bringt keine Verkettung mit. Die
 * Verzweigung schreibt man selbst — und genau das ist der Kern dieser Story: „stateful oder
 * stateless" ist damit keine Endpunkt-Entscheidung mehr, sondern eine Eigenschaft des
 * ausgestellten Tokens.
 *
 *   drei punktgetrennte Segmente + lesbarer JOSE-Header  ->  JWT-Zweig
 *   alles andere                                         ->  opaquer Zweig
 *
 * DER HEADER WIRD MITGEPRUEFT, nicht nur die zwei Punkte. Ein opaquer Token, den ein Projekt
 * ueber `addToken` selbst gewaehlt hat, darf Punkte enthalten — `pim_token.token` nimmt jede
 * Zeichenkette. Entschiede allein die Form, landete ein solcher Token im falschen Zweig und
 * wuerde abgewiesen, obwohl er in der Datenbank steht.
 *
 * BEIDE ZWEIGE SCHEITERN UNUNTERSCHEIDBAR. Jeder Fehlschlag — unbekannter Token, gesperrter
 * Benutzer, abgelaufen, Signatur falsch, kein Geheimnis gesetzt — wirft dieselbe Ausnahme mit
 * derselben Meldung. Verschiedene Meldungen verraten, welche Tokenart erwartet wird, und damit,
 * welche ein Angreifer bauen muss.
 *
 * DIESE FASSUNG VERIFIZIERT JWT, SIE STELLT KEINE AUS. Ausstellung, Refresh-Modell, Widerruf und
 * Schluesselwechsel sind `013-003`. Hier reicht ein Geheimnis aus der Umgebung.
 */
final class Tokenhandler implements AccessTokenHandlerInterface
{
    /**
     * Die eine Meldung fuer jeden Fehlschlag.
     *
     * Sie steht als Konstante da, damit niemand versehentlich eine zweite einfuehrt: Der Test,
     * der beide Zweige gegeneinander haelt, wuerde es zwar melden — aber eine Konstante macht
     * die Absicht schon beim Schreiben sichtbar.
     */
    public const ABWEISUNG = 'Ungueltiger Token.';

    /**
     * Der zuletzt aufgeloeste opaque Token — oder null.
     *
     * Der Aufrufer braucht ihn: `$app['auth.token']` traegt die Token-Entity, und das
     * Berechtigungsmodell liest sie. Ihn danach ein zweites Mal zu suchen waere eine zweite
     * Abfrage fuer eine Zeile, die gerade in der Hand lag.
     *
     * Ein Handler lebt einen Request lang — der Container legt ihn je Request an. Im JWT-Zweig
     * bleibt das Feld null, denn dort gibt es keine Zeile.
     */
    private ?Token $letzterToken = null;

    /**
     * Die Claims des zuletzt geprueften Access-JWT — oder null.
     *
     * Der Abmelden-Weg braucht `jti` und `exp`, um das Token auf die Sperrliste zu setzen
     * (013-003-0003). Sie ein zweites Mal aus dem Token zu lesen hiesse, ein zweites Mal die
     * Signatur zu pruefen — dieselbe Arbeit fuer dasselbe Ergebnis.
     *
     * @var array<string, mixed>|null
     */
    private ?array $letzteClaims = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $this->letzterToken = null;
        $this->letzteClaims = null;

        return $this->siehtNachJwtAus($accessToken)
            ? $this->ausJwt($accessToken)
            : $this->ausDatenbank($accessToken);
    }

    public function letzterToken(): ?Token
    {
        return $this->letzterToken;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function letzteClaims(): ?array
    {
        return $this->letzteClaims;
    }

    // ── Die Verzweigung ────────────────────────────────────────────────────────────────

    /**
     * Drei punktgetrennte Segmente, und das erste ist ein lesbarer JOSE-Header.
     *
     * Geprueft wird nur, ob der Header sich als JSON mit `alg` lesen laesst — nicht, ob das
     * Verfahren taugt. Diese Frage beantwortet der JWT-Zweig, und zwar mit einer Abweisung, wenn
     * sie falsch ausfaellt. Hier geht es allein darum, in welchen Zweig der Token gehoert.
     */
    private function siehtNachJwtAus(string $token): bool
    {
        $teile = explode('.', $token);

        if (count($teile) !== 3) {
            return false;
        }

        $roh = base64_decode(strtr($teile[0], '-_', '+/'), true);

        if ($roh === false) {
            return false;
        }

        $kopf = json_decode($roh, true);

        return is_array($kopf) && isset($kopf['alg']);
    }

    // ── Der JWT-Zweig ──────────────────────────────────────────────────────────────────

    /**
     * Signatur und Claims — und keine Abfrage auf `pim_token`.
     *
     * Das ist der ganze Gewinn dieses Zweigs: Der Sliding-Expiration-Write, den der opaque Zweig
     * bei JEDEM Request macht, entfaellt hier. `pim_user` wird trotzdem gelesen — ohne Benutzer
     * gibt es kein `UserBadge`, und das erledigt der `Benutzerlader`, indem dieses Badge
     * absichtlich OHNE eigenen Lader zurueckkommt.
     */
    private function ausJwt(string $token): UserBadge
    {
        /*
         * OHNE GEHEIMNIS WIRD ABGEWIESEN, nicht uebersprungen.
         *
         * Ein Zweig, der sich mangels Konfiguration selbst abschaltet, ist keine Pruefung. Die
         * Abweisung sieht aus wie jede andere — dass hier ein Geheimnis fehlt, ist eine Sache
         * des Betreibers und keine, die der Aufrufer erfahren muss.
         */
        if (!Zugangstoken::eingerichtet()) {
            $this->abweisen();
        }

        /*
         * DIE SCHLUESSEL WERDEN AUSSERHALB DES try GEHOLT (013-003-0004).
         *
         * `pruefschluessel()` wirft nur bei einer FEHLKONFIGURATION — zwei gleiche Kennungen,
         * oder ein vorheriger Schluessel ohne Kennung. Das ist kein ungueltiges Token, und es
         * darf nicht wie eines aussehen: Faenge man es hier mit ab, antwortete die Anwendung auf
         * jeden Request mit „ungueltiger Token", und der Betreiber suchte den Fehler bei seinen
         * Clients. So schlaegt sie laut durch, mit einer Meldung, die die Felder nennt.
         */
        $schluessel = Zugangstoken::pruefschluessel();

        try {
            /*
             * Ein Array statt eines einzelnen Keys: `JWT::decode()` waehlt dann nach dem `kid`
             * im Header. EIN TOKEN OHNE `kid` WIRD DAMIT ABGEWIESEN, und das ist die
             * Entscheidung: Ohne Kennung muesste die Anwendung raten, welcher Schluessel gemeint
             * ist — und „alle der Reihe nach probieren" hebt den Sinn des Wechsels auf, weil ein
             * abgeloester Schluessel dann weiter Tokens beglaubigt, die nichts ueber sich sagen.
             * Ausgestellt wurde ein Token ohne `kid` nie: Die Ausstellung entstand mit
             * 013-003-0001, die Kennung mit 013-003-0004, und dazwischen lag kein Release.
             */
            $claims = JWT::decode($token, $schluessel);
        } catch (\Throwable) {
            $this->abweisen();
        }

        /*
         * DER AUSGEBER WIRD GEPRUEFT (013-003-0001).
         *
         * Die Bibliothek prueft Signatur und Ablauf, den `iss` nicht. Ohne diese Zeile gaelte
         * hier jedes Token, das mit demselben Geheimnis signiert wurde — auch eines, das eine
         * ganz andere Anwendung fuer einen ganz anderen Zweck ausgestellt hat. Geteilte
         * Geheimnisse sind eine schlechte Idee, aber sie kommen vor, und dann soll die
         * Anwendung nicht das schwaechste Glied sein.
         */
        if (($claims->iss ?? null) !== Zugangstoken::AUSGEBER) {
            $this->abweisen();
        }

        $kennung = $claims->sub ?? null;

        if (!is_string($kennung) || $kennung === '') {
            $this->abweisen();
        }

        $jti = $claims->jti ?? null;

        if (!is_string($jti) || $jti === '') {
            $this->abweisen();
        }

        /*
         * DIE SPERRLISTE (013-003-0003).
         *
         * EIN LESEZUGRIFF, UND ER IST DER PREIS FUER DEN WIDERRUF. Ohne ihn gaelte ein
         * abgemeldetes Token bis zu seinem `exp` weiter — bei einem Token, das jemand abgegriffen
         * hat, ist genau das der Schaden.
         *
         * WAS DER JWT-ZWEIG DAMIT WEITERHIN NICHT TUT: `pim_token` anfassen. Der
         * Sliding-Expiration-Write bei JEDEM Request, der Grund fuer den ganzen Umbau, bleibt
         * weg. Hier steht ein Lesezugriff auf eine kleine Tabelle mit einem Unique-Index gegen
         * einen Schreibzugriff auf die Tokentabelle.
         */
        $gesperrt = $this->em->getRepository(RevokedToken::class)->findOneBy(array('jti' => $jti));

        if ($gesperrt instanceof RevokedToken) {
            $this->abweisen();
        }

        $this->letzteClaims = (array) $claims;

        // Ohne eigenen Lader: Den Benutzer holt der Benutzerlader, den der Authenticator kennt.
        return new UserBadge($kennung);
    }

    // ── Der opaque Zweig ───────────────────────────────────────────────────────────────

    /**
     * Was `BaseControllerProvider::checkToken()` seit jeher tut — unveraendert uebernommen.
     *
     * Nachgeschlagen wird der SHA-256 des vorgezeigten Tokens (`013-001-0004`), nicht er selbst.
     * Der Timeout kommt aus der Gruppe des Benutzers, sonst aus `APP_TOKEN_TIMEOUT`; ein Token
     * mit `referrer` ist ein API-Token und verfaellt nicht. Ein abgelaufener wird geloescht, ein
     * gueltiger auf `modified` zurueckgeschrieben — der Sliding-Expiration-Write.
     */
    private function ausDatenbank(string $token): UserBadge
    {
        $zeile = $this->em->getRepository(Token::class)->findOneBy(
            array('token' => Token::hashen($token))
        );

        if (!$zeile instanceof Token) {
            $this->abweisen();
        }

        /*
         * EIN REFRESH-TOKEN IST KEIN ZUGANGSTOKEN (013-003-0001).
         *
         * Es ist eine gewoehnliche Zeile in `pim_token` — und dieser Zweig nahm bis hierhin
         * jede Zeile an. Ein Refresh-Token gilt laenger als ein Access-JWT, das ist sein Zweck;
         * ohne diese Pruefung waere es damit ein langlebiger Generalschluessel fuer die ganze
         * API, also genau das, was das Refresh-Modell verhindern soll.
         *
         * Abgewiesen wird wie alles andere: Wer ein Refresh-Token an der falschen Tuer
         * vorzeigt, erfaehrt nicht, dass es an einer anderen passen wuerde.
         */
        if ($zeile->istRefreshToken()) {
            $this->abweisen();
        }

        $benutzer = $zeile->getUser();

        if (!$benutzer || !$benutzer->getIsActive()) {
            $this->abweisen();
        }

        if (self::abgelaufen($zeile, $benutzer)) {
            $this->em->remove($zeile);
            $this->em->flush();

            $this->abweisen();
        }

        if (self::timeoutGilt($zeile)) {
            $zeile->setModified(new \DateTime());
            $this->em->flush();
        }

        $this->letzterToken = $zeile;

        // MIT eigenem Lader: Der Benutzer liegt schon vor. Ihn ueber den Benutzerlader noch
        // einmal zu holen waere eine zweite Abfrage fuer dieselbe Zeile.
        return new UserBadge($benutzer->getUserIdentifier(), static fn () => $benutzer);
    }

    // ── Ablauf, an einer Stelle ────────────────────────────────────────────────────────

    /**
     * Ob fuer diese Zeile ueberhaupt ein Timeout gilt.
     *
     * Ein Token mit `referrer` ist ein API-Token und verfaellt nicht; und der Betreiber kann
     * die Pruefung ganz abschalten. Beides steht seit jeher so da.
     */
    public static function timeoutGilt(Token $zeile): bool
    {
        return (bool) Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT && !$zeile->getReferrer();
    }

    /**
     * Die Lebensdauer einer Token-Zeile in Sekunden.
     *
     * Die Gruppe des Benutzers schlaegt die Vorgabe — und sie rechnet in MINUTEN. Das ist ein
     * Erbe und keine Schoenheit, aber es ist das Verhalten von frueher.
     */
    public static function timeoutFuer(User $benutzer): int
    {
        if (($gruppe = $benutzer->getGroup())) {
            return (int) $gruppe->getTokenTimeout() * 60;
        }

        return (int) Adapter::getConfig()->APP_TOKEN_TIMEOUT;
    }

    /**
     * Ob diese Zeile abgelaufen ist.
     *
     * HERAUSGEZOGEN MIT 013-003-0002: Der Refresh-Weg braucht dieselbe Rechnung. Zwei Kopien
     * derselben Ablauflogik laufen auseinander, und die eine, die es dann falsch macht, laesst
     * jemanden laenger herein als gedacht.
     */
    public static function abgelaufen(Token $zeile, User $benutzer): bool
    {
        if (!self::timeoutGilt($zeile)) {
            return false;
        }

        $timeout = self::timeoutFuer($benutzer);

        if (!$timeout) {
            return false;
        }

        return (time() - $zeile->getModified()->getTimestamp()) > $timeout;
    }

    private function abweisen(): never
    {
        throw new CustomUserMessageAuthenticationException(self::ABWEISUNG);
    }
}
