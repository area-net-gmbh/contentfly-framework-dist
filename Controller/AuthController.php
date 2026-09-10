<?php
namespace Areanet\PIM\Controller;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\LoginProvider;
use Areanet\PIM\Classes\Security\Fremdkennung;
use Areanet\PIM\Classes\Security\Tokenhandler;
use Areanet\PIM\Classes\Security\Zugangstoken;
use Areanet\PIM\Entity\RevokedToken;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;


class AuthController extends BaseController
{
    /*
     * CHECK_LOGIN_INTERVAL UND MIN_LOGIN_INTERVAL SIND ENTFALLEN (013-001-0003).
     *
     * Hier standen zwei Konstanten und weiter unten ein Zweig, der nie lief:
     * `CHECK_LOGIN_INTERVAL` war fest `false`. Selbst eingeschaltet waere es ein
     * 60-Sekunden-Abstand pro Benutzer gewesen, gemessen am zuletzt ausgestellten Token —
     * gegen das Raten ueber viele Konten hinweg wirkungslos, und gegen das Raten vieler
     * Passwoerter zu EINEM Konto nur dann, wenn zwischendurch ein Token entstand. Ein
     * Angreifer, der nie richtig raet, stellt nie einen Token aus.
     *
     * An die Stelle tritt `Areanet\PIM\Classes\Security\Anmeldebremse`: pro Kennung UND pro
     * IP, mit ansteigender Verzoegerung, und ohne Schalter, der sie ausknipst.
     */

    /**
     * @apiVersion 1.3.0
     * @api {post} /auth/login login
     * @apiName Login
     * @apiGroup User
     * @apiDescription API-Endpoint zur Authentifizierung eines Benutzers.
     *
     * Über einen benutzerdefinierten Login-Manager kann das standardmäßige Login-Verhalten des Contentfly CMS erweitert oder angepasst werden.
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} alias Benutzername
     * @apiParam {String} pass Passwort
     * @apiParam {String} loginManager Optionaler Login-Manager
     * @apiParam {String} tokenType "jwt" fuer ein Access-JWT samt Refresh-Token; ohne Angabe ein opaques Token (013-003-0001)
     * @apiParam {Boolean} withSchema Schema zurückgeben
     * @apiParamExample {json} Request-Beispiel:
     *     {
     *      "alias": "admin",
     *      "pass": "xyz"
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "Login successful",
     *       "token": "sdnajn3sdfmkwrk23cskvavdfgq45sdfasgafg"
     *       "user": {
     *          "alias": "admin",
     *          "isAdmin": true
     *      }
     *   }
     * @apiError 401 Ungültiger Benutzername | Der Benutzer ist gesperrt | Benutzername und/oder Passwort fehlerhaft
     * @apiError 429 Zu viele Anmeldeversuche - die Bremse greift pro Kennung und pro IP (013-001-0003)
     */
    public function loginAction(Request $request)
    {
        $kennung = ($request->request->all()['alias'] ?? null);
        $ip      = $request->getClientIp();

        /** @var \Areanet\PIM\Classes\Security\Anmeldebremse $bremse */
        $bremse = $this->app['loginbremse'];

        /*
         * ERST BREMSEN, DANN PRUEFEN (013-001-0003).
         *
         * Die Reihenfolge ist der Punkt: Wer ueber der Grenze ist, kommt gar nicht erst bis zur
         * Datenbankabfrage und zum Passwortvergleich. Stuende die Bremse hinter der Pruefung,
         * kostete jeder abgewiesene Versuch weiterhin einen Argon2id-Durchlauf — die Sperre
         * waere dann selbst der Hebel fuer eine Ueberlastung.
         *
         * DIE ANTWORT SAGT NICHTS UEBER DIE KENNUNG. Sie faellt fuer einen bekannten und einen
         * erfundenen Benutzernamen gleich aus; andernfalls waere die Bremse ein Orakel dafuer,
         * welche Konten es gibt. `Retry-After` nennt nur, wie lange zu warten ist — das steht
         * dem legitimen Benutzer zu, der sich dreimal vertippt hat.
         */
        if (($wartezeit = $bremse->wartezeit($kennung, $ip)) !== null) {
            return new JsonResponse(
                array('message' => 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.'),
                429,
                array('Retry-After' => $wartezeit)
            );
        }

        /*
         * JEDER FEHLSCHLAG GEHT DURCH DIESE EINE STELLE.
         *
         * Vorher standen fuenf `return new JsonResponse(..., 401)` nebeneinander. Wer der Reihe
         * nach jedem einzelnen ein `$bremse->fehlversuch(...)` voranstellt, vergisst
         * irgendwann eines — und ein einziger ungezaehlter Zweig ist der Weg, an der Bremse
         * vorbeizuraten.
         */
        $abweisen = function ($meldung) use ($bremse, $kennung, $ip) {
            $bremse->fehlversuch($kennung, $ip);

            return new JsonResponse(array('message' => $meldung), 401);
        };

        /*
         * DER NAME WAEHLT AUS EINER ALLOWLIST, NICHT EINE KLASSE (013-004-0001).
         *
         * Hier stand der Klassenname aus dem Request, aufgeloest zu `Custom\Classes\<Name>`.
         * Der Praefix und eine `instanceof`-Pruefung begrenzten den Schaden — aber die Auswahl
         * lag beim Aufrufer, und welche Klasse eine Anwendung instanziiert, ist eine
         * Entscheidung des Betreibers.
         *
         * Der Parameter heisst weiterhin `loginManager`: Bestandsclients schicken ihn so, und
         * ihn umzubenennen waere ein Bruch am Draht ohne Gewinn innerhalb dieser Story. Was er
         * benennt, ist jetzt ein Eintrag im Verzeichnis — ein Klassenname steht dort nicht und
         * wird damit abgewiesen wie jeder andere unbekannte Name.
         */
        $anbieterName = ($request->request->all()['loginManager'] ?? null);
        $loginProvider = null;

        if (!empty($anbieterName)) {
            $loginProvider = $this->app['anmeldeanbieter']->holen(is_string($anbieterName) ? $anbieterName : null);

            /*
             * EIN UNBEKANNTER NAME WIRD ABGEWIESEN, nicht auf die Passwortpruefung
             * zurueckgefuehrt. Sonst waere ein Tippfehler im Providernamen eine stille
             * Anmeldung ueber den falschen Weg — und ein geratener Name ein Orakel dafuer,
             * welche Fremdsysteme diese Installation kennt.
             */
            if (!$loginProvider) {
                return $abweisen('Ungültiger Benutzername.');
            }
        }

        if ($loginProvider) {
            try {
                $fremd = $loginProvider->pruefen($request);
            } catch (\Throwable) {
                /*
                 * Auch eine Ausnahme ist eine Ablehnung. Ihre Meldung nach aussen zu geben —
                 * so lief es bis 013-004-0001 — machte den Provider zum Erzaehler: Ein
                 * LDAP-Fehler stand woertlich in der Antwort, samt Servernamen.
                 */
                $fremd = null;
            }

            if (!$fremd instanceof Fremdkennung) {
                return $abweisen('Benutzername und/oder Passwort fehlerhaft.');
            }

            /*
             * NOCH KEINE PROVISIONIERUNG (013-004-0001).
             *
             * Gefunden wird ein Benutzer, der es schon gibt. Anlegen, Passwort sperren und die
             * Fremdkennung in einer eigenen Spalte fuehren ist `013-004-0002` — bis dahin
             * bleibt `createManagedUser()` unangetastet, und dieser Weg meldet nur an, was
             * bereits eingerichtet ist.
             */
            $user = $this->em->getRepository('Areanet\PIM\Entity\User')->findOneBy(
                array('alias' => $fremd->kennung)
            );

            if (!$user instanceof User) {
                return $abweisen('Ungültiger Benutzername.');
            }

            if (!$user->getIsActive()) {
                return $abweisen('Der Benutzer ist gesperrt.');
            }
        }else{

            $user = $this->em->getRepository('Areanet\PIM\Entity\User')->findOneBy(array('alias' => $kennung));
            if(!$user){
                return $abweisen('Ungültiger Benutzername.');
            }

            if(!$user->getIsActive()){
                return $abweisen('Der Benutzer ist gesperrt.');
            }

            if($user->getLoginManager()){
                return $abweisen('Der Benutzer ist nur über LoginManager authorisierbar.');
            }

            /*
             * EIN ZWEIG, KEINE FALLUNTERSCHEIDUNG (013-001-0002).
             *
             * Hier stand eine zweite Bedingung: Ist APP_MASTER_PASSWORD gesetzt, genuegte
             * dieser eine Wert fuer JEDEN Benutzer. Die Konstante ist ersatzlos entfallen —
             * ein Schalter, der Vollzugriff gewaehrt, ist auch ausgeschaltet eine Hintertuer.
             */
            if(!$user->isPass(($request->request->all()['pass'] ?? null))){
                return $abweisen('Benutzername und/oder Passwort fehlerhaft.');
            }
        }

        /*
         * UMSCHLUESSELUNG BEIM LOGIN (013-001-0001).
         *
         * Passt das Passwort und liegt der Hash noch im alten SHA-256-Format — oder mit
         * veralteten Parametern —, wird er hier ersetzt. Kein Zwangs-Reset, keine Migration im
         * Voraus: Nach dem ersten Login jedes Benutzers ist der alte Hash weg.
         *
         * ES STEHT HIER UND NICHT IN `isPass()`: Eine Pruefung darf nichts schreiben. Sonst
         * haette jeder Aufruf eine Nebenwirkung, auch der aus `Api::doUpdate()`, wo das
         * bisherige Passwort nur bestaetigt wird.
         *
         * Der Weg ueber den LoginManager ist ausgenommen — dort prueft ein Fremdsystem, und
         * `$user->getPass()` steht in keinem Zusammenhang mit dem eingegebenen Wort.
         */
        if (!$loginProvider && $user->brauchtNeuenHash()) {
            $user->setPass(($request->request->all()['pass'] ?? null));
            $this->em->flush();
        }

        /*
         * DIE GELUNGENE ANMELDUNG LOESCHT DEN ZAEHLER DIESER KENNUNG (013-001-0003).
         *
         * Nur den der Kennung, nicht den der IP: Sonst genuegte einem Angreifer ein einziges
         * gueltiges Konto — sein eigenes —, um sich nach jedem Block wieder freizuschalten.
         */
        $bremse->entsperren($kennung);

        /*
         * WELCHEN TOKENTYP DER LOGIN AUSGIBT (013-003-0001).
         *
         * NUR AUF ANFORDERUNG, und ausdruecklich NICHT ueber einen Konfigurationsschalter. Ein
         * solcher Schalter kippte die Antwort fuer JEDEN Client auf einmal — und die Zusage
         * dieser Story lautet, dass ein Bestandsclient nichts merkt. Wer JWT will, sagt es je
         * Anfrage; wer nichts sagt, bekommt, was er immer bekommen hat.
         */
        $jwtGewuenscht = strtolower((string) ($request->request->all()['tokenType'] ?? '')) === 'jwt';

        if ($jwtGewuenscht && !Zugangstoken::eingerichtet()) {
            /*
             * Die Meldung richtet sich an den Betreiber, nicht an den Aufrufer: Sie nennt das
             * fehlende Feld und sonst nichts. Ueber Konten, Passwoerter oder vorhandene Tokens
             * sagt sie nichts aus — ein Angreifer erfaehrt nur, dass diese Installation keine
             * JWT ausstellt.
             */
            return new JsonResponse(
                array('message' => 'JWT sind auf dieser Installation nicht eingerichtet: SECURITY_JWT_SECRET fehlt.'),
                500
            );
        }

        $token = new Token();
        $token->setUser($user);

        if ($jwtGewuenscht) {
            // Die Zeile wird zum Refresh-Token. Als Zugangstoken taugt sie damit nicht mehr —
            // der opaque Zweig des Tokenhandler weist sie ab.
            $token->setPurpose(Token::ZWECK_REFRESH);
        }

        $this->em->persist($token);
        $this->em->flush();

        $this->app['auth.user'] = $user;

        $response = array(
            'message' => 'Login successful',
            // getKlartext(), nicht getToken(): In der Spalte steht seit 013-001-0004 nur der
            // Hash. Dies ist die einzige Stelle und der einzige Zeitpunkt, an dem der Token
            // selbst das System verlaesst — danach existiert er nur noch beim Client.
            'token' => $token->getKlartext(),
            'user' => $user->toValueObject($this->app, 'PIM\User', false)
        );

        if ($jwtGewuenscht) {
            $zugang = Zugangstoken::ausstellen($user);

            /*
             * `token` bleibt das, was der Client vorzeigt — jetzt eben das Access-JWT. Damit
             * aendert sich fuer einen umsteigenden Client genau ein Feldwert und kein Feldname.
             * Das Refresh-Token kommt daneben; einloesen laesst es sich ab 013-003-0002.
             */
            $response['token']        = $zugang['token'];
            $response['refreshToken'] = $token->getKlartext();
            $response['expiresIn']    = $zugang['exp'] - time();
        }

        if(($tempData = $user->getTempData())){
            $response['data'] = $tempData;
        }

        if(($request->request->all()['withSchema'] ?? null)){
            $api = new Api($this->app);
            $response['schema'] = $api->getExtendedSchema();
            $response['hash']   = $this->app['schema']['_hash'];
        }

        return new JsonResponse($response);

    }

    /**
     * @apiVersion 1.5.0
     * @api {post} /auth/refresh refresh
     * @apiName Refresh
     * @apiGroup User
     * @apiDescription Tauscht ein Refresh-Token gegen ein frisches Access-JWT (013-003-0002).
     *
     * @apiParam {String} refreshToken Das Refresh-Token aus der Anmeldung
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "Refresh successful",
     *       "token": "eyJ...",
     *       "refreshToken": "…",
     *       "expiresIn": 900
     *     }
     * @apiError 401 Ungültiges Refresh-Token
     * @apiError 429 Zu viele Versuche
     */
    public function refreshAction(Request $request)
    {
        $ip     = $request->getClientIp();
        $bremse = $this->app['loginbremse'];

        /*
         * DIE BREMSE GILT AUCH HIER (013-003-0002).
         *
         * Ein Endpunkt, der Zugangstokens ausgibt, ist dasselbe Ziel wie der Login. Ihn
         * ungebremst zu lassen hiesse, die Bremse an der Vordertuer anzubringen und die
         * Seitentuer offen zu lassen.
         *
         * GEBREMST WIRD NUR UEBER DIE ADRESSE, nicht ueber eine Kennung: Der Request bringt
         * keine mit. Das ist auch die richtige Achse — ein Refresh-Token laesst sich nicht ueber
         * einen Benutzernamen erraten, sondern nur durch Durchprobieren von einer Stelle aus.
         */
        if (($wartezeit = $bremse->wartezeit(null, $ip)) !== null) {
            return new JsonResponse(
                array('message' => 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.'),
                429,
                array('Retry-After' => $wartezeit)
            );
        }

        /*
         * JEDER FEHLSCHLAG SIEHT GLEICH AUS.
         *
         * Unbekannt, abgelaufen, schon verbraucht, gesperrter Benutzer, oder ein Access-JWT an
         * der falschen Tuer — der Aufrufer erfaehrt nur, dass es nicht gereicht hat. Wer hier
         * unterscheidet, sagt einem Angreifer, welcher seiner Versuche naeher dran war.
         */
        $abweisen = function () use ($bremse, $ip) {
            $bremse->fehlversuch(null, $ip);

            return new JsonResponse(array('message' => 'Ungültiges Refresh-Token.'), 401);
        };

        $vorgezeigt = ($request->request->all()['refreshToken'] ?? null);

        if (!is_string($vorgezeigt) || $vorgezeigt === '') {
            return $abweisen();
        }

        $zeile = $this->em->getRepository('Areanet\PIM\Entity\Token')->findOneBy(
            array('token' => Token::hashen($vorgezeigt))
        );

        // Kein Treffer — oder ein Treffer, der kein Refresh-Token ist. Ein Zugangstoken taugt
        // hier nicht: Sonst waere die Trennung aus 013-003-0001 in eine Richtung wieder auf.
        if (!$zeile instanceof Token || !$zeile->istRefreshToken()) {
            return $abweisen();
        }

        $benutzer = $zeile->getUser();

        if (!$benutzer || !$benutzer->getIsActive()) {
            return $abweisen();
        }

        if (Tokenhandler::abgelaufen($zeile, $benutzer)) {
            $this->em->remove($zeile);
            $this->em->flush();

            return $abweisen();
        }

        if (!Zugangstoken::eingerichtet()) {
            return new JsonResponse(
                array('message' => 'JWT sind auf dieser Installation nicht eingerichtet: SECURITY_JWT_SECRET fehlt.'),
                500
            );
        }

        /*
         * ROTATION: DAS VORGEZEIGTE TOKEN WIRD ERSETZT.
         *
         * Ein Refresh-Token, das mehrfach gilt, ist ein langlebiges Geheimnis: Wer es abgreift,
         * holt sich damit beliebig lange frische Zugangstokens, und niemand sieht es. Wird es
         * bei jedem Gebrauch getauscht, faellt ein zweiter Gebrauch desselben Tokens auf — er
         * wird abgewiesen, weil die Zeile nicht mehr existiert.
         *
         * Die alte Zeile wird geloescht und eine neue angelegt, statt den Wert an Ort und
         * Stelle zu tauschen: Ein Token IST seine Zeile, und `created` soll sagen, wann dieses
         * Token entstand.
         */
        $this->em->remove($zeile);

        $neu = new Token();
        $neu->setUser($benutzer);
        $neu->setPurpose(Token::ZWECK_REFRESH);

        $this->em->persist($neu);
        $this->em->flush();

        $this->app['auth.user'] = $benutzer;

        $zugang = Zugangstoken::ausstellen($benutzer);

        return new JsonResponse(array(
            'message'      => 'Refresh successful',
            'token'        => $zugang['token'],
            'refreshToken' => $neu->getKlartext(),
            'expiresIn'    => $zugang['exp'] - time(),
        ));
    }

    /**
     * @apiVersion 1.3.0
     * @api {get} /auth/logout logout
     * @apiName Logout
     * @apiGroup User
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     * @apiParam {String} refreshToken Optional; wer mit einem Access-JWT abmeldet, entzieht damit
     *                                 zugleich sein Refresh-Token (013-003-0003)
     */
    public function logoutAction(Request $request)
    {
        /*
         * NICHT JEDE ANMELDUNG HAT EINE ZEILE (013-002-0004).
         *
         * `$app['auth.token']` traegt die Zeile aus `pim_token` — im JWT-Zweig gibt es keine.
         */
        if ($this->app['auth.token']) {
            $this->em->remove($this->app['auth.token']);
        }

        /*
         * ABMELDEN BEI EINEM ZUSTANDSLOSEN TOKEN (013-003-0003).
         *
         * Zwei Dinge, und beide sind noetig:
         *
         *   1. Das vorgezeigte Access-JWT auf die Sperrliste, bis zu seinem `exp`. Sonst gaelte
         *      es nach dem Abmelden weiter — bei einem abgegriffenen Token ist genau das der
         *      Schaden.
         *   2. Das Refresh-Token loeschen. Sonst holt sich der Inhaber gleich ein neues
         *      Access-JWT, und Punkt 1 war umsonst.
         *
         * DER CLIENT MUSS SEIN REFRESH-TOKEN MITSCHICKEN, und das ist eine bewusste
         * Entscheidung. Das Access-JWT sagt nicht, zu welcher Refresh-Zeile es gehoert — die
         * Verbindung stuende sonst als sechster Claim darin, und der Claim-Satz aus
         * `013-003-0001` ist absichtlich klein. Alle Refresh-Zeilen des Benutzers zu loeschen
         * waere die Alternative; das meldete ihn auf allen seinen Geraeten ab, was beim Abmelden
         * an einem davon niemand erwartet.
         *
         * Ohne mitgeschicktes Refresh-Token wird nur das Access-JWT gesperrt; die Refresh-Zeile
         * verfaellt dann ueber ihr eigenes Zeitlimit.
         */
        if (($claims = $this->app['tokenhandler']->letzteClaims())) {
            $sperre = new RevokedToken();
            $sperre->setJti((string) $claims['jti']);
            $sperre->setExpiresAt((new \DateTime())->setTimestamp((int) $claims['exp']));

            $this->em->persist($sperre);
        }

        $mitgeschickt = $request->query->get('refreshToken') ?? ($request->request->all()['refreshToken'] ?? null);

        if (is_string($mitgeschickt) && $mitgeschickt !== '') {
            $zeile = $this->em->getRepository('Areanet\PIM\Entity\Token')->findOneBy(
                array('token' => Token::hashen($mitgeschickt))
            );

            /*
             * NUR DAS EIGENE. Ohne diese Pruefung waere `logout` ein Endpunkt, mit dem ein
             * beliebiger angemeldeter Benutzer fremde Sitzungen beenden koennte — er muesste
             * nur ein fremdes Refresh-Token raten oder in die Finger bekommen.
             */
            if ($zeile instanceof Token
                && $zeile->istRefreshToken()
                && $zeile->getUser() === $this->app['auth.user']) {
                $this->em->remove($zeile);
            }
        }

        $this->em->flush();

        unset($this->app['auth.token']);
        unset($this->app['auth.user']);

        return new JsonResponse(array('message' => 'Logout successful'));
    }
}
