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
     * @var string  Cache-Driver filesystem,apc,memcached
     */
    public $APP_CACHE_DRIVER = 'filesystem';

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
     * @var string Default sender mail address
     */
    public $APP_MAILFROM = null;

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

    /**
     * @var string Masterpasswort für die Authentifizierung
     */
    public $APP_MASTER_PASSWORD     = null;


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
     * @var string (Unter)Ordner für <base href>
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
    public function __construct($host = 'default', Config $config = null)
    {

        if($config !== null){
            foreach (get_object_vars($config) as $key => $value) {
                $this->$key = $value;
            }
        }

        $this->host = $host;
    }
}
