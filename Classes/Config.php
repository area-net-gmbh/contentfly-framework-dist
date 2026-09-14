<?php
namespace Areanet\PIM\Classes;


use PHPMailer\PHPMailer\PHPMailer;

/**
 * Class Config
 * @package Areanet\PIM\Classes
 */
class Config{
    /**
     * Hostname for config settings
     *
     * @var string
     */
    protected $host = 'default';

    /**
     * PATH TO CLI-PHP
     *
     * @var string
     */
    public $SYSTEM_PHP_CLI_COMMAND = 'php';

    /**
     * @var string $DB_HOST Database Server Host
     */
    public $DB_HOST     = null;

    /**
     * @var string $DB_NAME Database Name
     */
    public $DB_NAME     = null;

    /**
     * @var string $DB_USER Database Username
     */
    public $DB_USER     = null;

    /**
     * @var string $DB_PASS Database Password
     */
    public $DB_PASS     = null;

    /**
     * @var string $DB_PORT Database Password
     */
    public $DB_PORT     = 3306;

    /**
     * @var string $DB_HOST Database Charset
     */
    public $DB_CHARSET  = 'utf8';

    /**
     * @var string $DB_HOST Database Collate
     */
    public $DB_COLLATE  = 'utf8_unicode_ci';

    /**
     * @var string $DB_NESTED_LEVELS Loading x nested levels
     */
    public $DB_NESTED_LEVELS  = 3;

    /**
     * @var boolean $DB_GUID_STRATEGY Set primary types to guid
     */
    public $DB_GUID_STRATEGY  = true;

    /**
     * @var boolean $DB_ID_INTEGER_TYPE Type of id integer fields
     */
    public $DB_ID_INTEGER_TYPE  = 'integer';

    /**
     * @var boolean Doctrine erstellt Proxy-Klassen automatisch zur Laufzeit
     */
    public $APP_AUTOGENERATE_PROXIES = true;

    /**
     * @var boolean Enable Schema Cache
     */
    public $APP_ENABLE_SCHEMA_CACHE = true;

    /**
     * Metadaten- und Abfrage-Cache von Doctrine.
     *
     * Erlaubt sind `filesystem` (Vorgabe), `apcu` und `memcached`. Der Cache ist nur aktiv,
     * wenn `APP_DEBUG` aus ist und die Anwendung nicht auf der Konsole laeuft.
     *
     * `apc` ist mit `010-002-0002` entfallen und wird ausdruecklich abgewiesen statt
     * stillschweigend auf die Vorgabe zurueckzufallen: Die APC-Erweiterung gibt es fuer
     * PHP 7 und 8 nicht mehr — der Zweig konnte auf keiner unterstuetzten Version laufen.
     * Der Nachfolger heisst `apcu`; er stand hier nie in der Liste, obwohl der Bootstrap ihn
     * seit jeher behandelte.
     *
     * @var string  filesystem | apcu | memcached
     */
    public $APP_CACHE_DRIVER = 'filesystem';

    /**
     * Server fuer `APP_CACHE_DRIVER = 'memcached'`, als DSN.
     *
     * NEU MIT 010-002-0002, und zwar aus einem Befund: Vorher baute der Bootstrap ein blankes
     * `new Memcached()` — einen Client **ohne einen einzigen Server**. Ein solcher Client
     * speichert nichts; der Zweig war also selbst dort wirkungslos, wo die Erweiterung
     * vorhanden war.
     *
     * @var string
     */
    public $APP_CACHE_MEMCACHED_DSN = 'memcached://localhost:11211';

    /**
     * @var integer Token Timeout in ms
     */
    public $APP_TOKEN_TIMEOUT = 1800;

    /**
     * @var boolean Check Token Timeout
     */
    public $APP_CHECK_TOKEN_TIMEOUT = true;

    /**
     * @var string Default recipient mail address
     */
    public $APP_MAILTO = null;


    /**
     * @var boolean Show detailed error messages
     */
    public $APP_DEBUG = false;

    /**
     * @var string Timezone for PIM
     */
    public $APP_TIMEZONE = 'Europe/Berlin';

    /**
     * @var array Sprachen
     */
    public $APP_LANGUAGES = array();

    /**
     * @var string Art der Dateirückgabe über API /file/get: redirect, readfile, xsendfile
     */
    public $APP_FILE_MODE = 'redirect';

    public $MAILER_EXCEPTIONS = false;

    public $MAILER_DEBUG = 0;

    public $MAILER_SMTP_HOST = null;

    public $MAILER_SMTP_USERNAME = null;

    public $MAILER_SMTP_PASSWORD = null;

    public $MAILER_SMTP_SECURE = PHPMailer::ENCRYPTION_STARTTLS;

    public $MAILER_SMTP_PORT = 465;

    public $MAILER_FROM = null;

    public $MAILER_FROM_NAME= null;


    public $APP_SYSTEM_TYPES = array(
        '\\Areanet\\PIM\\Classes\\Types\\BooleanType',
        '\\Areanet\\PIM\\Classes\\Types\\IntegerType',
        '\\Areanet\\PIM\\Classes\\Types\\DatetimeType',
        '\\Areanet\\PIM\\Classes\\Types\\DecimalType',
        '\\Areanet\\PIM\\Classes\\Types\\FloatType',
        '\\Areanet\\PIM\\Classes\\Types\\TextareaType',
        '\\Areanet\\PIM\\Classes\\Types\\JsonType',
        '\\Areanet\\PIM\\Classes\\Types\\StringType',
        '\\Areanet\\PIM\\Classes\\Types\\TimeType',
        '\\Areanet\\PIM\\Classes\\Types\\SelectType',
        '\\Areanet\\PIM\\Classes\\Types\\OnejoinType',
        '\\Areanet\\PIM\\Classes\\Types\\JoinType',
        '\\Areanet\\PIM\\Classes\\Types\\JoinBidirectionalType',
        '\\Areanet\\PIM\\Classes\\Types\\FileType',
        '\\Areanet\\PIM\\Classes\\Types\\MultifileType',
        '\\Areanet\\PIM\\Classes\\Types\\MultijoinType',
        '\\Areanet\\PIM\\Classes\\Types\\PermissionsType',
        '\\Areanet\\PIM\\Classes\\Types\\VirtualjoinType',
        '\\Areanet\\PIM\\Classes\\Types\\CheckboxType',
        '\\Areanet\\PIM\\Classes\\Types\\RadioType',
        '\\Areanet\\PIM\\Classes\\Types\\I18nPermissionsType'
    );

    /**
     * @var string Allow CORS '*' or 'domain.de'
     */
    public $APP_CS_POLICY        = "default-src 'self' 'unsafe-inline' 'unsafe-eval'  https://maxcdn.bootstrapcdn.com;";

    /**
     * @var string Allow CORS '*' or 'domain.de'
     */
    public $APP_ALLOW_ORIGIN        = null;

    /**
     * @var string Allow Credentials
     */
    public $APP_ALLOW_CREDENTIALS   = 'false';

    /**
     * @var string Allow Credentials
     */
    public $APP_ALLOW_CREDENTIALS_SDK   = 'true';

    /**
     * @var string Allowed Methods
     */
    public $APP_ALLOW_METHODS       = 'POST, GET, OPTIONS, DELETE, PUT';

    /**
     * @var string Allowed Headers
     */
    public $APP_ALLOW_HEADERS       = 'content-type, x-xsrf-token';

    /**
     * @var string Allowed Headers
     */
    public $APP_ALLOW_HEADERS_SDK       = 'Access-Control-Allow-Origin,Access-Control-Allow-Methods,Access-Control-Allow-Headers,contentfly-ionic,content-type, x-xsrf-token, appcms-token, authorization';


    /**
     * @var string CORS max age
     */
    public $APP_MAX_AGE             = 0;

    /*
     * APP_MASTER_PASSWORD IST ERSATZLOS ENTFALLEN (013-001-0002).
     *
     * Ein hier gesetzter Wert akzeptierte den Login fuer JEDEN Benutzer — eine
     * Konfigurationszeile mit Vollzugriff auf jedes Konto.
     *
     * Nicht abschaltbar gemacht, sondern entfernt. Ein Schalter, der Vollzugriff gewaehrt, ist
     * auch ausgeschaltet eine Hintertuer: Er kann versehentlich gesetzt werden, er steht in
     * Konfigurationsbeispielen, und er laedt dazu ein, ihn "nur kurz" zu benutzen.
     *
     * DASS DAS PROBLEM BEKANNT WAR, IST AKTENKUNDIG: Das Kundenprojekt, aus dem dieses
     * Framework herausgeschnitten wurde, setzte den Wert beim Bootstrap ausdruecklich auf null
     * (siehe an_project/docs/technical.md). Man hat sich davor geschuetzt, statt ihn zu
     * entfernen.
     */


    /**
     * Proxies, hinter denen die Anwendung steht.
     *
     * NEU MIT 013-001-0003, und zwar als Voraussetzung fuer die LoginThrottle: `setTrustedProxies()`
     * wurde im ganzen Baum nirgends gerufen. Ohne diese Angabe liefert
     * `Request::getClientIp()` die Adresse des naechsten Hops — hinter einem Loadbalancer also
     * dessen eigene. Eine Begrenzung pro IP traefe dann ihn und damit alle Benutzer dahinter,
     * waehrend der Angreifer ungebremst weiterraet.
     *
     * LEER HEISST: unveraendert. Wer keine Proxies eintraegt, betreibt die Anwendung direkt —
     * dann stimmt die Adresse ohnehin, und `setTrustedProxies()` wird gar nicht erst gerufen.
     *
     * Erlaubt sind einzelne Adressen, CIDR-Netze und der Sonderwert 'REMOTE_ADDR' von
     * HttpFoundation ("der unmittelbare Absender, wer immer das ist") — als Array oder als
     * Zeichenkette mit Kommas, damit der Wert auch aus einer Umgebungsvariablen kommen kann.
     *
     * Beispiel: array('10.0.0.0/8', '192.168.1.5')
     *
     * @var array|string
     */
    public $APP_TRUSTED_PROXIES = array();

    /**
     * Welchen Weiterleitungs-Headern dabei geglaubt wird.
     *
     * Die Vorgabe ist die enge: nur die X-Forwarded-*-Header. `forwarded` schaltet stattdessen
     * auf den standardisierten Einzelheader aus RFC 7239 um. BEIDE GLEICHZEITIG GIBT ES NICHT —
     * sie transportieren dieselbe Angabe, und beiden zu glauben hiesse, dem Aufrufer die Wahl zu
     * lassen, welche gilt.
     *
     * Ein unbekannter Wert wird abgewiesen statt stillschweigend auf die Vorgabe zurueckgefuehrt;
     * ein Tippfehler waere sonst eine Konfiguration, die zu wirken scheint und nicht wirkt.
     *
     * @var string  x-forwarded | forwarded
     */
    public $APP_TRUSTED_HEADERS = 'x-forwarded';

    /**
     * @var string Force SSL-Connection
     */
    public $APP_FORCE_SSL = false;

    /**
     * @var string HTTP Authentification User
     */
    public $APP_HTTP_AUTH_USER = null;

    /**
     * @var string HTTP Authentification Password
     */
    public $APP_HTTP_AUTH_PASS = null;

    /*
     * VON ZEHN FRONTEND_*-FELDERN SIND ACHT ENTFALLEN (000-000-0010).
     *
     * Epic 012 hat die PIM-Oberflaeche entfernt; 012-005-0004 zog nur die drei Felder mit,
     * die es selbst verwaist hatte, und vermerkte den Rest als eigenen Task. Entfallen sind:
     *
     *   FRONTEND_UI, FRONTEND_URL, FRONTEND_CUSTOM_LOGIN_BG   niemand las sie
     *   FRONTEND_TITLE, FRONTEND_WELCOME, FRONTEND_LOGIN_REDIRECT,
     *   FRONTEND_CUSTOM_LOGO, FRONTEND_FORM_IMAGE_SQUARE_PREVIEW
     *                                                          nur der frontend-Block des
     *                                                          Schemas las sie, und der
     *                                                          bewarb damit Eigenschaften
     *                                                          einer geloeschten Oberflaeche
     *
     * Die beiden folgenden bleiben. Sie tragen "FRONTEND_" im Namen, steuern aber Verhalten
     * der API — die Benennung ist ein Erbe, kein Hinweis auf ihren Zweck. Umbenennen waere
     * ein Bruch fuer jedes Bestandsprojekt und gehoert, wenn ueberhaupt, zu Epic 007.
     */

    /**
     * @var boolean Schaltet die benutzerdefinierte Navigation im Schema frei.
     *
     * BLEIBT: Steuert einen datengetriebenen Zweig in Api::getExtendedSchema(), der die
     * Entities PIM\Nav und PIM\NavItem ausliest. Beide existieren, sind Teil des
     * Datenmodells und werden von der Suite beruehrt — das ist kein Rest der Oberflaeche.
     */
    public $FRONTEND_CUSTOM_NAVIGATION = false;

    /**
     * @var integer Standard-Seitengroesse der Pagination von /api/list und /api/query
     *
     * BLEIBT: Wird in ApiController::listAction() als Vorgabe fuer itemsPerPage gelesen und
     * bestimmt damit, wie viele Objekte ein Client ohne eigene Angabe bekommt. Reines
     * API-Verhalten.
     */
    public $FRONTEND_ITEMS_PER_PAGE = 40;


    /**
     * @var array Register File Processors
     */
    public $FILE_PROCESSORS = array('\Areanet\PIM\Classes\File\Processing\Image');


    /**
     * @var boolean Filenhash must be unique
     */
    public $FILE_HASH_MUST_UNIQUE = false;

    /**
     * @var integer Qualität, 0..100 / 100 = keine Komprimierung
     */
    public $FILE_IMAGE_QUALITY_JPEG = 90;

    /**
     * @var integer Qualität, 0..9 / 0 = keine Komprimierung
     */
    public $FILE_IMAGE_QUALITY_PNG = 0;

    /**
     * @var boolean Lifetime for HTTP-File-Cache = 7 Tage
     */
    public $FILE_CACHE_LIFETIME = 604800;



    /**
     * @var string ImageMagick-Path
     */
    public $IMAGEMAGICK_EXECUTABLE = 'convert';

    public $SECURITY_CIPHER_METHOD = 'AES-256-CBC';
    public $SECURITY_CIPHER_KEY    = null;

    /**
     * The signing secret for JWTs.
     *
     * NEW WITH 013-002-0003, and like `SECURITY_CIPHER_KEY` **without a default value**. A secret
     * stored in the repository is no secret: every installation that forgets to set it would then
     * sign with a publicly known value — and nobody notices, because everything works. Without a
     * value the JWT branch rejects every token instead of silently skipping it.
     *
     * The value belongs in the environment, not in a committed file; the shipped
     * `custom/config.php` reads it from there.
     *
     * AT LEAST 32 BYTES. `firebase/php-jwt` from 7.0 rejects a shorter secret for HS256
     * ("Provided key is too short") — when signing as well as when verifying. The handler catches
     * that together with every other error: an operator with a secret that is too short does not
     * get a half-working system but none at all. That is the right direction, because HS256 with a
     * short secret can be guessed.
     *
     * JWTs are only issued from `013-003` on. This version only verifies — key rotation with a key
     * ID in the token header and a transition period belong to that story.
     *
     * @var string|null
     */
    public $SECURITY_JWT_SECRET    = null;

    /**
     * Lifetime of an access JWT in seconds. Default: 15 minutes.
     *
     * NEW WITH 013-003-0001. Being short-lived is the entire security feature of a stateless token:
     * it cannot be recalled while it is valid, so its duration decides the size of the window. It
     * is renewed through the refresh token without anyone having to log in again.
     *
     * Whoever raises the value buys convenience with exactly this window.
     *
     * @var integer
     */
    public $SECURITY_JWT_TTL       = 900;

    /**
     * The ID of the current signing key — it appears as `kid` in the token header.
     *
     * NEW WITH 013-003-0004. Without an ID a key could only be rotated by ending all running
     * sessions — and a key whose rotation hurts does not get rotated. A leak would then be
     * permanent.
     *
     * The value is a name, not a secret: it appears in plain text in every token. `k1`, `k2`, a
     * date — whatever makes it recognisable at the next rotation which key is meant.
     *
     * @var string
     */
    public $SECURITY_JWT_KEY_ID    = 'k1';

    /**
     * The previous signing key, during a transition period.
     *
     * HOW A ROTATION WORKS: move the current value here, put a new one in `SECURITY_JWT_SECRET`,
     * set both key IDs. From then on tokens are signed with the new key, and both are accepted —
     * nobody has to log in again. Once the longest access JWT issued at that time has expired
     * (`SECURITY_JWT_TTL`), the two `*_PREVIOUS` fields can be emptied again.
     *
     * @var string|null
     */
    public $SECURITY_JWT_SECRET_PREVIOUS = null;

    /**
     * The ID of the previous key.
     *
     * Must differ from `SECURITY_JWT_KEY_ID` — otherwise two IDs would point to the same name, and
     * one of the two keys would silently disappear. The application rejects that instead of letting
     * it happen.
     *
     * @var string|null
     */
    public $SECURITY_JWT_KEY_ID_PREVIOUS = null;

    /**
     * How an external system is mapped to Contentfly groups (013-004-0003).
     *
     * ONE ENTRY PER PROVIDER NAME, with three keys:
     *
     *   groups   external group => name of a Contentfly group. The FIRST match in this order wins —
     *            which makes the order a decision and not a coincidence.
     *   admin    list of external groups that set the admin flag.
     *   default  group for the case that nothing matches. If it is missing, the user stays without
     *            a group.
     *
     * WITHOUT AN ENTRY NOTHING HAPPENS — no change of group, and above all NO admin flag. A mapping
     * that grants permissions when in doubt goes in the wrong direction; the external system should
     * justify permissions, not their absence.
     *
     * IT APPLIES ON EVERY LOGIN. Whoever drops out of a group in the external system drops out here as
     * well on their next login — that is exactly why roles are NOT in the JWT (`013-003-0001`).
     *
     * Example:
     *
     *     array('ldap' => array(
     *         'groups'  => array('CN=Editors' => 'Editors', 'CN=Admins' => 'Administrators'),
     *         'admin'   => array('CN=Admins'),
     *         'default' => 'Guests',
     *     ))
     *
     * @var array<string, array{groups?: array<string,string>, admin?: array<int,string>, default?: string|null}>
     */
    public $SECURITY_PROVIDER_GROUPS = array();

    /*
     * ── LDAP / Active Directory (013-005-0001) ────────────────────────────────────────
     *
     * Only needed for a project that registers `Classes\Security\LdapProvider` in `custom/app.php`.
     * Without a registration none of this is in use.
     *
     * THE APPROACH IS SEARCH, THEN BIND — and not the direct bind with a DN built from the identifier.
     * That only works as long as all users sit flat in one OU; in Active Directory they do not, and
     * login there uses `sAMAccountName`, which does not appear in the DN at all. The price is a
     * service account — or an anonymous search, where the directory allows it.
     */

    /** @var string Host of the directory, e.g. 'ldap.example.invalid' */
    public $SECURITY_LDAP_HOST = null;

    /** @var integer */
    public $SECURITY_LDAP_PORT = 389;

    /** @var string none | ssl | tls */
    public $SECURITY_LDAP_ENCRYPTION = 'none';

    /** @var string Base of the search, e.g. 'OU=Users,DC=example,DC=invalid' */
    public $SECURITY_LDAP_BASE_DN = null;

    /**
     * The service account for the search — or empty for an anonymous search.
     *
     * No default and no value in the repository: both belong in the environment.
     *
     * @var string|null
     */
    public $SECURITY_LDAP_SEARCH_DN = null;

    /** @var string|null */
    public $SECURITY_LDAP_SEARCH_PASSWORD = null;

    /**
     * The search filter. `{identifier}` is replaced by the escaped input.
     *
     * The default is the Active Directory case. For OpenLDAP it is usually `(uid={identifier})`.
     *
     * @var string
     */
    public $SECURITY_LDAP_FILTER = '(sAMAccountName={identifier})';

    /**
     * Where the groups come from.
     *
     * `memberOf` sits on the user entry and is the usual way in Active Directory. What it contains goes
     * UNCHANGED into the `ExternalIdentity`; it is mapped by `SECURITY_PROVIDER_GROUPS` (013-004-0003)
     * and not here.
     *
     * @var string
     */
    public $SECURITY_LDAP_GROUP_ATTRIBUTE = 'memberOf';

    /*
     * ── OIDC (013-005-0003) ───────────────────────────────────────────────────────────
     *
     * Only needed for a project that registers `Classes\Security\OidcProvider`.
     *
     * THE USERINFO APPROACH IS CHOSEN, not local verification against a JWKS. Measured on 2026-09-11:
     * three lightweight packages versus five with `web-token/jwt-library` and
     * `spomky-labs/pki-framework` among them, and a revocation takes effect immediately instead of only
     * on expiry. The price is one HTTP request per login and the dependency on the provider — which
     * however ONLY concerns the login: Contentfly issues its own token afterwards (013-003), and the
     * OIDC token is verified exactly once.
     */

    /** @var string|null The provider's userinfo endpoint, full URL */
    public $SECURITY_OIDC_USERINFO_ENDPOINT = null;

    /**
     * Which field of the userinfo response carries the identifier.
     *
     * `sub` is the OpenID Connect standard and the only field a provider is guaranteed to return.
     * `email` or `preferred_username` are more convenient and **changeable** — whoever maps onto them
     * gets a new account as soon as someone gets married.
     *
     * @var string
     */
    public $SECURITY_OIDC_IDENTIFIER_CLAIM = 'sub';

    /**
     * Which field carries the groups. Empty means: no groups.
     *
     * The name is not standardised — `groups`, `roles`, `realm_access.roles` depending on the provider.
     * What it contains goes unchanged into the `ExternalIdentity`; it is mapped by
     * `SECURITY_PROVIDER_GROUPS` (013-004-0003).
     *
     * @var string
     */
    public $SECURITY_OIDC_GROUPS_CLAIM = 'groups';


    /**
     * Unter welchem Pfad die Anwendung im Web erreichbar ist.
     *
     * Liegt sie im Wurzelverzeichnis des Hosts, bleibt es bei '/'. Liegt sie in einem
     * Unterverzeichnis, gehoert hier '/unterverzeichnis/' hin — mit Schraegstrich am Ende.
     *
     * Bis 000-000-0006 hat `bootstrap-web.php` diesen Wert bei jedem Request aus
     * `$_SERVER['PHP_SELF']` ueberschrieben. Das traf unter Apache mit der mitgelieferten
     * .htaccess zu und sonst nirgends; unter dem eingebauten PHP-Server zeigte die
     * Dateiauslieferung anschliessend auf `/index.php/file/get/data/files/…`. Der Mountpunkt
     * ist eine Angabe des Betreibers, keine, die sich aus der Umgebung erraten laesst.
     *
     * Benutzt von `FileController::getAction()` fuer den Redirect auf `data/files/…`.
     *
     * @var string
     */
    public $WEB_ROOT = '/';

    /**
     * Get hostname for config settings
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Config constructor.
     *
     * @param string $host Hostname for config settings
     * @param Config $config Copy this config settings for overwriting
     */
    public function __construct($host = 'default', ?Config $config = null)
    {

        if($config !== null){
            foreach (get_object_vars($config) as $key => $value) {
                $this->$key = $value;
            }
        }

        $this->host = $host;
    }
}
