<?php
namespace Areanet\PIM\Controller;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\LoginProvider;
use Areanet\PIM\Classes\Manager\LoginManager;
use Areanet\PIM\Classes\Security\Zugangstoken;
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

        $loginProviderClass = ($request->request->all()['loginManager'] ?? null);
        if(($loginProvider = $this->getLoginProvider($request, $loginProviderClass))){
            try {
                $user = $loginProvider->auth();
                if(!($user instanceof User)){
                    return $abweisen('Ungültiger Benutzer vom LoginManager');
                }
            }catch(\Exception $e){
                return $abweisen($e->getMessage());
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
     * @apiVersion 1.3.0
     * @api {get} /auth/logout logout
     * @apiName Logout
     * @apiGroup User
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     */
    public function logoutAction()
    {
        /*
         * NICHT JEDE ANMELDUNG HAT EINE ZEILE (013-002-0004).
         *
         * `$app['auth.token']` traegt die Zeile aus `pim_token` — im JWT-Zweig gibt es keine,
         * und das ist der ganze Gewinn jenes Zweigs. Ausgestellt werden JWT erst mit `013-003`;
         * die Pruefung steht trotzdem schon hier, weil die Moeglichkeit mit dieser Story
         * entsteht und nicht mit jener.
         *
         * Was Abmelden bei einem zustandslosen Token bedeutet — Refresh-Token entziehen,
         * Restfenster ueber eine Sperrliste —, entscheidet `013-003`.
         */
        if ($this->app['auth.token']) {
            $this->em->remove($this->app['auth.token']);
            $this->em->flush();
        }

        unset($this->app['auth.token']);
        unset($this->app['auth.user']);

        return new JsonResponse(array('message' => 'Logout successful'));
    }

    protected function getLoginProvider(Request $request, $loginProviderClassName){
        if(empty($loginProviderClassName)){
            return null;
        }

        $loginProviderClass = self::providerKlasse($loginProviderClassName);

        if(!class_exists($loginProviderClass)){
            return null;
        }

        $loginProvider = new $loginProviderClass($this->app, $request);
        if(!($loginProvider instanceof LoginManager)){
            return null;
        }

        return $loginProvider;
    }

    /**
     * Loest den Namen eines LoginManagers zur Klasse auf (013-001-0005).
     *
     * DIE BEDINGUNG WAR VERDREHT. Hier stand `substr($name, 7) == 'Plugins'` — das schneidet
     * **ab** Position 7, statt die ersten sieben Zeichen zu pruefen. Fuer
     * `Plugins\Auth\Ldap` ergibt das `\Auth\Ldap`, die Bedingung greift nie, und der Name
     * wurde faelschlich zu `Custom\Classes\Plugins\Auth\Ldap`. **LoginManager aus Plugins
     * funktionierten dadurch nicht** — und es fiel niemandem auf, weil `plugins/` leer ist.
     *
     * Ein Name mit `Plugins`-Praefix bleibt jetzt, wie er ist; jeder andere wird unter
     * `Custom\Classes\` gesucht.
     *
     * Herausgezogen als eigene Methode, damit die Aufloesung ohne laufende Anwendung pruefbar
     * ist: Ein end-to-end-Nachweis braeuchte ein Plugin, und es gibt keines.
     */
    public static function providerKlasse(string $name): string
    {
        return str_starts_with($name, 'Plugins')
            ? $name
            : 'Custom\Classes\\'.$name;
    }
}