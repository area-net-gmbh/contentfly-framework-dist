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
     * NEU MIT 013-001-0003, und zwar als Voraussetzung fuer die Anmeldebremse: `setTrustedProxies()`
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
     * Das Signaturgeheimnis fuer JWT.
     *
     * NEU MIT 013-002-0003, und wie `SECURITY_CIPHER_KEY` **ohne Standardwert**. Ein im
     * Repository hinterlegtes Geheimnis ist keines: Jede Installation, die vergisst es zu
     * setzen, signierte dann mit einem oeffentlich bekannten Wert — und niemand merkt es, weil
     * alles funktioniert. Ohne Wert weist der JWT-Zweig jeden Token ab, statt ihn
     * stillschweigend zu ueberspringen.
     *
     * Der Wert gehoert in die Umgebung, nicht in eine committete Datei; die ausgelieferte
     * `custom/config.php` liest ihn von dort.
     *
     * MINDESTENS 32 BYTE. `firebase/php-jwt` ab 7.0 weist ein kuerzeres Geheimnis fuer HS256 ab
     * („Provided key is too short") — beim Signieren wie beim Pruefen. Der Handler faengt das
     * mit jedem anderen Fehler ab: Ein Betreiber mit zu kurzem Geheimnis bekommt kein halb
     * funktionierendes System, sondern gar keines. Das ist die richtige Richtung, denn HS256
     * mit einem kurzen Geheimnis ist ratbar.
     *
     * Ausgestellt werden JWT erst mit `013-003`. Diese Fassung verifiziert nur — Schluesselwechsel
     * mit Kennung im Token-Header und Uebergangszeit gehoeren zu jener Story.
     *
     * @var string|null
     */
    public $SECURITY_JWT_SECRET    = null;

    /**
     * Lebensdauer eines Access-JWT in Sekunden. Vorgabe: 15 Minuten.
     *
     * NEU MIT 013-003-0001. Kurzlebigkeit ist die ganze Sicherheitsleistung eines zustandslosen
     * Tokens: Es laesst sich nicht zurueckrufen, solange es gilt, also entscheidet die Dauer
     * ueber die Groesse des Fensters. Erneuert wird ueber das Refresh-Token, ohne dass sich
     * jemand neu anmelden muss.
     *
     * Wer den Wert hochsetzt, kauft sich Bequemlichkeit mit genau diesem Fenster.
     *
     * @var integer
     */
    public $SECURITY_JWT_TTL       = 900;

    /**
     * Die Kennung des aktuellen Signaturschluessels — sie steht als `kid` im Token-Header.
     *
     * NEU MIT 013-003-0004. Ohne Kennung liesse sich ein Schluessel nur wechseln, indem man alle
     * laufenden Sitzungen beendet — und ein Schluessel, dessen Wechsel wehtut, wird nicht
     * gewechselt. Damit waere ein Leak dauerhaft.
     *
     * Der Wert ist ein Name, kein Geheimnis: Er steht im Klartext in jedem Token. `k1`, `k2`,
     * ein Datum — was immer beim naechsten Wechsel erkennbar macht, welcher Schluessel gemeint
     * ist.
     *
     * @var string
     */
    public $SECURITY_JWT_KEY_ID    = 'k1';

    /**
     * Der vorherige Signaturschluessel, waehrend einer Uebergangszeit.
     *
     * SO LAEUFT EIN WECHSEL AB: Den bisherigen Wert hierher, einen neuen nach
     * `SECURITY_JWT_SECRET`, beide Kennungen setzen. Signiert wird ab sofort mit dem neuen,
     * angenommen werden beide — niemand muss sich neu anmelden. Wenn das laengste zu dieser Zeit
     * ausgestellte Access-JWT abgelaufen ist (`SECURITY_JWT_TTL`), koennen die beiden
     * `*_PREVIOUS`-Felder wieder leer.
     *
     * @var string|null
     */
    public $SECURITY_JWT_SECRET_PREVIOUS = null;

    /**
     * Die Kennung des vorherigen Schluessels.
     *
     * Muss sich von `SECURITY_JWT_KEY_ID` unterscheiden — sonst zeigten zwei Kennungen auf
     * denselben Namen, und eine der beiden Faessungen verschwaende stillschweigend. Die Anwendung
     * weist das ab, statt es geschehen zu lassen.
     *
     * @var string|null
     */
    public $SECURITY_JWT_KEY_ID_PREVIOUS = null;

    /**
     * Wie ein Fremdsystem auf Contentfly-Gruppen abgebildet wird (013-004-0003).
     *
     * JE ANBIETERNAME EIN EINTRAG, mit drei Schluesseln:
     *
     *   gruppen  Fremdgruppe => Name einer Contentfly-Gruppe. Der ERSTE Treffer in dieser
     *            Reihenfolge gewinnt — die Reihenfolge ist damit eine Entscheidung und kein
     *            Zufall.
     *   admin    Liste von Fremdgruppen, die das Adminflag setzen.
     *   vorgabe  Gruppe fuer den Fall, dass nichts passt. Fehlt sie, bleibt der Benutzer ohne
     *            Gruppe.
     *
     * OHNE EINTRAG PASSIERT NICHTS — kein Gruppenwechsel, und vor allem KEIN Adminflag. Eine
     * Abbildung, die im Zweifel Rechte vergibt, ist die falsche Richtung; das Fremdsystem soll
     * Rechte begruenden, nicht ihr Fehlen.
     *
     * SIE WIRKT BEI JEDER ANMELDUNG. Wer im Fremdsystem aus einer Gruppe faellt, faellt beim
     * naechsten Login auch hier heraus — genau deshalb stehen Rollen NICHT im JWT
     * (`013-003-0001`).
     *
     * Beispiel:
     *
     *     array('ldap' => array(
     *         'gruppen' => array('CN=Redaktion' => 'Redakteure', 'CN=Admins' => 'Administratoren'),
     *         'admin'   => array('CN=Admins'),
     *         'vorgabe' => 'Gaeste',
     *     ))
     *
     * @var array<string, array{gruppen?: array<string,string>, admin?: array<int,string>, vorgabe?: string|null}>
     */
    public $SECURITY_PROVIDER_GRUPPEN = array();

    /*
     * ── LDAP / Active Directory (013-005-0001) ────────────────────────────────────────
     *
     * Nur noetig fuer ein Projekt, das `Classes\Security\LdapProvider` in `custom/app.php`
     * eintraegt. Ohne Eintrag ist nichts davon in Gebrauch.
     *
     * DER WEG IST SUCHEN, DANN BINDEN — und nicht der direkte Bind mit einem aus der Kennung
     * zusammengesetzten DN. Der funktioniert nur, solange alle Benutzer flach in einer OU
     * liegen; im Active Directory tun sie das nicht, und angemeldet wird dort mit
     * `sAMAccountName`, der im DN gar nicht vorkommt. Der Preis ist ein Dienstkonto — oder
     * eine anonyme Suche, wo das Verzeichnis sie erlaubt.
     */

    /** @var string Host des Verzeichnisses, z.B. 'ldap.example.invalid' */
    public $SECURITY_LDAP_HOST = null;

    /** @var integer */
    public $SECURITY_LDAP_PORT = 389;

    /** @var string none | ssl | tls */
    public $SECURITY_LDAP_ENCRYPTION = 'none';

    /** @var string Basis der Suche, z.B. 'OU=Benutzer,DC=example,DC=invalid' */
    public $SECURITY_LDAP_BASE_DN = null;

    /**
     * Das Dienstkonto fuer die Suche — oder leer fuer eine anonyme Suche.
     *
     * Kein Standardwert und kein Wert im Repo: Beides gehoert in die Umgebung.
     *
     * @var string|null
     */
    public $SECURITY_LDAP_SEARCH_DN = null;

    /** @var string|null */
    public $SECURITY_LDAP_SEARCH_PASSWORD = null;

    /**
     * Der Suchfilter. `{kennung}` wird durch die maskierte Eingabe ersetzt.
     *
     * Vorgabe ist der Active-Directory-Fall. Fuer ein OpenLDAP ist es meist `(uid={kennung})`.
     *
     * @var string
     */
    public $SECURITY_LDAP_FILTER = '(sAMAccountName={kennung})';

    /**
     * Woher die Gruppen kommen.
     *
     * `memberOf` steht am Benutzereintrag und ist der uebliche Weg im Active Directory. Was
     * dort steht, geht UNVERAENDERT in die `Fremdkennung`; abgebildet wird es von
     * `SECURITY_PROVIDER_GRUPPEN` (013-004-0003) und nicht hier.
     *
     * @var string
     */
    public $SECURITY_LDAP_GRUPPEN_ATTRIBUT = 'memberOf';

    /*
     * ── OIDC (013-005-0003) ───────────────────────────────────────────────────────────
     *
     * Nur noetig fuer ein Projekt, das `Classes\Security\OidcProvider` eintraegt.
     *
     * GEWAEHLT IST DER USERINFO-WEG, nicht die lokale Pruefung gegen ein JWKS. Gemessen am
     * 2026-09-11: drei leichte Pakete gegen fuenf mit `web-token/jwt-library` und
     * `spomky-labs/pki-framework` darin, und ein Widerruf wirkt sofort statt erst mit dem
     * Ablauf. Der Preis ist eine HTTP-Anfrage je Anmeldung und die Abhaengigkeit vom Provider —
     * die aber NUR die Anmeldung betrifft: Contentfly stellt danach ein eigenes Token aus
     * (013-003), der OIDC-Token wird genau einmal geprueft.
     */

    /** @var string|null Der Userinfo-Endpunkt des Providers, vollstaendige URL */
    public $SECURITY_OIDC_USERINFO_ENDPOINT = null;

    /**
     * Welches Feld der Userinfo-Antwort die Kennung traegt.
     *
     * `sub` ist der Standard aus OpenID Connect und das einzige Feld, das ein Provider
     * garantiert liefert. `email` oder `preferred_username` sind bequemer und **aenderbar** —
     * wer darauf abbildet, bekommt ein neues Konto, sobald jemand heiratet.
     *
     * @var string
     */
    public $SECURITY_OIDC_KENNUNG_CLAIM = 'sub';

    /**
     * Welches Feld die Gruppen traegt. Leer heisst: keine Gruppen.
     *
     * Der Name ist nicht standardisiert — `groups`, `roles`, `realm_access.roles` je nach
     * Provider. Was dort steht, geht unveraendert in die `Fremdkennung`; abgebildet wird es von
     * `SECURITY_PROVIDER_GRUPPEN` (013-004-0003).
     *
     * @var string
     */
    public $SECURITY_OIDC_GRUPPEN_CLAIM = 'groups';


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
