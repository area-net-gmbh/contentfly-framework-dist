<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\Token;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $this->letzterToken = null;

        return $this->siehtNachJwtAus($accessToken)
            ? $this->ausJwt($accessToken)
            : $this->ausDatenbank($accessToken);
    }

    public function letzterToken(): ?Token
    {
        return $this->letzterToken;
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
        $geheimnis = Adapter::getConfig()->SECURITY_JWT_SECRET;

        /*
         * OHNE GEHEIMNIS WIRD ABGEWIESEN, nicht uebersprungen.
         *
         * Ein Zweig, der sich mangels Konfiguration selbst abschaltet, ist keine Pruefung. Die
         * Abweisung sieht aus wie jede andere — dass hier ein Geheimnis fehlt, ist eine Sache
         * des Betreibers und keine, die der Aufrufer erfahren muss.
         */
        if (!is_string($geheimnis) || $geheimnis === '') {
            $this->abweisen();
        }

        try {
            $claims = JWT::decode($token, new Key($geheimnis, Zugangstoken::VERFAHREN));
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

        $timeout = Adapter::getConfig()->APP_TOKEN_TIMEOUT;

        if (($gruppe = $benutzer->getGroup())) {
            $timeout = $gruppe->getTokenTimeout() * 60;
        }

        if (Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT && !$zeile->getReferrer() && $timeout) {
            $jetzt      = new \DateTime();
            $verstrichen = $jetzt->getTimestamp() - $zeile->getModified()->getTimestamp();

            if ($verstrichen > $timeout) {
                $this->em->remove($zeile);
                $this->em->flush();

                $this->abweisen();
            }

            $zeile->setModified($jetzt);
            $this->em->flush();
        }

        $this->letzterToken = $zeile;

        // MIT eigenem Lader: Der Benutzer liegt schon vor. Ihn ueber den Benutzerlader noch
        // einmal zu holen waere eine zweite Abfrage fuer dieselbe Zeile.
        return new UserBadge($benutzer->getUserIdentifier(), static fn () => $benutzer);
    }

    private function abweisen(): never
    {
        throw new CustomUserMessageAuthenticationException(self::ABWEISUNG);
    }
}
