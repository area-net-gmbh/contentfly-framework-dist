<?php
namespace Areanet\PIM\Classes;


use PHPMailer\PHPMailer\PHPMailer;

/**
 * Class Config
 * @package Areanet\PIM\Classes
 *
 * ── Projects add keys of their own — and may (000-000-0040) ─────────────────────────
 *
 * `custom/config.php` has always set keys that are not declared here: SMTP settings, OAuth
 * endpoints, frontend URLs. The existing project UFP sets 35 of them. Up to PHP 8.1 that was plain
 * PHP; since 8.2 every dynamic property is deprecated.
 *
 * The deprecation is not noise in a log. `custom/config.php` runs BEFORE the bootstrap sets
 * `display_errors`, so on a server without a php.ini that turns it off — the official php images have
 * none — PHP writes one HTML block per key in front of the JSON. Measured at UFP (007-005-0003):
 * `/api/v2/core/config` answered 200 with `text/html` and 35 `Deprecated` blocks. It would also turn
 * every project's deprecation gate red.
 *
 * DECIDED ON 2026-09-15: own keys are allowed, explicitly. Not chosen: a subclass per project that
 * declares its keys — a migration step that protects nothing, since a key a project sets and reads
 * itself cannot collide with the framework's, and misspelling one of the framework's keys is not
 * caught by declaring the project's either.
 */
#[\AllowDynamicProperties]
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
     * @var bool|int When Doctrine writes its proxy classes: true = on every request, false = never (run
     *               orm:generate-proxies on deployment), or a ProxyFactory::AUTOGENERATE_* constant.
     *               Default: only when a proxy is missing or its entity changed (000-000-0047).
     */
    public $APP_AUTOGENERATE_PROXIES = \Areanet\PIM\Classes\ORM\ProxyGeneration::DEFAULT;

    /**
     * @var boolean Enable Schema Cache
     */
    public $APP_ENABLE_SCHEMA_CACHE = true;

    /**
     * Doctrine's metadata and query cache.
     *
     * Allowed are `filesystem` (default), `apcu` and `memcached`. The cache is only active
     * when `APP_DEBUG` is off and the application is not running on the console.
     *
     * `apc` was dropped with `010-002-0002` and is explicitly rejected instead of silently
     * falling back to the default: the APC extension no longer exists for PHP 7 and 8 — the
     * branch could not run on any supported version. Its successor is called `apcu`; it was
     * never in the list here, even though the bootstrap had always handled it.
     *
     * @var string  filesystem | apcu | memcached
     */
    public $APP_CACHE_DRIVER = 'filesystem';

    /**
     * Server for `APP_CACHE_DRIVER = 'memcached'`, as a DSN.
     *
     * NEW WITH 010-002-0002, and specifically because of a finding: previously the bootstrap
     * built a bare `new Memcached()` — a client **without a single server**. Such a client
     * stores nothing; the branch was therefore ineffective even where the extension was
     * installed.
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
     * @var array Languages
     */
    public $APP_LANGUAGES = array();

    /**
     * @var string How files are returned via API /file/get: redirect, readfile, xsendfile
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
     * @var string|array<int,string>|null The origins a browser client may call the API from —
     *      exact values such as `https://app.example.com` or `capacitor://localhost`, as an array
     *      or a comma-separated string. `*` allows any origin, but without credentials.
     *
     * null (the default) allows no foreign origin: `Access-Control-Allow-Origin` is not sent.
     * Until 000-000-0039 this field was never read, and every origin was allowed with credentials.
     * See Classes/Security/CorsPolicy.
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
     * APP_MASTER_PASSWORD HAS BEEN REMOVED WITHOUT REPLACEMENT (013-001-0002).
     *
     * A value set here accepted the login for EVERY user — a configuration line with full
     * access to every account.
     *
     * Not made switchable, but removed. A switch that grants full access is a backdoor even
     * when switched off: it can be set by accident, it appears in configuration examples, and
     * it invites people to use it "just briefly".
     *
     * THAT THE PROBLEM WAS KNOWN IS ON RECORD: the customer project from which this framework
     * was carved out explicitly set the value to null during bootstrap
     * (see an_project/docs/technical.md). People protected themselves against it instead of
     * removing it.
     */


    /**
     * Proxies the application sits behind.
     *
     * NEW WITH 013-001-0003, specifically as a prerequisite for the LoginThrottle:
     * `setTrustedProxies()` was not called anywhere in the whole tree. Without this setting,
     * `Request::getClientIp()` returns the address of the nearest hop — behind a load balancer,
     * that is the balancer's own. A per-IP limit would then hit the balancer and with it all users
     * behind it, while the attacker keeps guessing unhindered.
     *
     * EMPTY MEANS: unchanged. Whoever enters no proxies runs the application directly — then the
     * address is correct anyway, and `setTrustedProxies()` is not even called.
     *
     * Allowed are single addresses, CIDR networks and HttpFoundation's special value
     * 'REMOTE_ADDR' ("the immediate sender, whoever that is") — as an array or as a
     * comma-separated string, so that the value can also come from an environment variable.
     *
     * Example: array('10.0.0.0/8', '192.168.1.5')
     *
     * @var array|string
     */
    public $APP_TRUSTED_PROXIES = array();

    /**
     * Which forwarding headers are trusted in the process.
     *
     * The default is the narrow one: only the X-Forwarded-* headers. `forwarded` switches instead
     * to the standardised single header from RFC 7239. BOTH AT THE SAME TIME IS NOT AN OPTION —
     * they carry the same information, and trusting both would mean letting the caller choose
     * which one applies.
     *
     * An unknown value is rejected instead of silently being reset to the default; otherwise a
     * typo would be a configuration that appears to work and does not.
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
     * OF TEN FRONTEND_* FIELDS, EIGHT HAVE BEEN DROPPED (000-000-0010).
     *
     * Epic 012 removed the PIM user interface; 012-005-0004 only took along the three fields
     * it had orphaned itself, and recorded the rest as a separate task. Dropped are:
     *
     *   FRONTEND_UI, FRONTEND_URL, FRONTEND_CUSTOM_LOGIN_BG   nobody read them
     *   FRONTEND_TITLE, FRONTEND_WELCOME, FRONTEND_LOGIN_REDIRECT,
     *   FRONTEND_CUSTOM_LOGO, FRONTEND_FORM_IMAGE_SQUARE_PREVIEW
     *                                                          only the schema's frontend
     *                                                          block read them, and it
     *                                                          thereby advertised properties
     *                                                          of a deleted user interface
     *
     * The following two stay. They carry "FRONTEND_" in their name, but control behaviour of
     * the API — the naming is a legacy, not a hint at their purpose. Renaming them would be a
     * break for every existing project and belongs, if anywhere, to Epic 007.
     */

    /**
     * @var boolean Enables the custom navigation in the schema.
     *
     * STAYS: controls a data-driven branch in Api::getExtendedSchema() that reads the
     * entities PIM\Nav and PIM\NavItem. Both exist, are part of the data model and are
     * exercised by the suite — this is not a leftover of the user interface.
     */
    public $FRONTEND_CUSTOM_NAVIGATION = false;

    /**
     * @var integer Default page size of the pagination of /api/list and /api/query
     *
     * STAYS: read in ApiController::listAction() as the default for itemsPerPage and thereby
     * determines how many objects a client gets without specifying its own value. Pure
     * API behaviour.
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
     * @var array<string, list<string>>|null Optional whitelist for uploads: extension => content
     *      types it may carry, e.g. `array('png' => array('image/png'), 'pdf' => array('application/pdf'))`.
     *
     * null (the default) accepts every name that passes the floor in
     * `Classes\File\UploadValidator` — which always rejects executable extensions and server
     * configuration names, whatever is set here. When set, the extension has to be listed and the
     * type detected from the file's content has to match (000-000-0038).
     */
    public $FILE_ALLOWED_TYPES = null;

    /**
     * @var int|null Largest accepted upload in bytes, checked by `Classes\File\UploadValidator` before
     *               anything is stored (413). null = only PHP's `upload_max_filesize` applies
     *               (000-000-0042).
     */
    public $FILE_MAX_UPLOAD_SIZE = null;

    /**
     * @var integer Quality, 0..100 / 100 = no compression
     */
    public $FILE_IMAGE_QUALITY_JPEG = 90;

    /**
     * @var integer Quality, 0..9 / 0 = no compression
     */
    public $FILE_IMAGE_QUALITY_PNG = 0;

    /**
     * @var boolean Lifetime for HTTP-File-Cache = 7 days
     */
    public $FILE_CACHE_LIFETIME = 604800;



    /**
     * @var int|null Largest image, in pixels (width × height), that the image processor accepts.
     *               Checked from the image header by `Classes\File\UploadValidator` before
     *               anything is stored (413); null = no limit (000-000-0068).
     *
     * GD decodes an image into memory at about four bytes per pixel, whatever the file size. A
     * few hundred bytes of PNG header can claim 50,000 × 50,000 pixels — ten gigabytes. The
     * default admits a 24-megapixel photo (6000 × 4000); `memory_limit` has to cover roughly
     * five bytes per admitted pixel.
     *
     * `IMAGEMAGICK_EXECUTABLE` IS GONE with the ImageMagick processor it configured, also
     * 000-000-0068: see an_project/docs/breaking-changes.md.
     */
    public $FILE_IMAGE_MAX_PIXELS = 24000000;

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
     * The path under which the application is reachable on the web.
     *
     * If it lives in the host's root directory, it stays '/'. If it lives in a subdirectory,
     * '/subdirectory/' belongs here — with a trailing slash.
     *
     * Until 000-000-0006, `bootstrap-web.php` overwrote this value on every request from
     * `$_SERVER['PHP_SELF']`. That was correct under Apache with the shipped .htaccess and
     * nowhere else; under the built-in PHP server, file delivery then pointed to
     * `/index.php/file/get/data/files/…`. The mount point is something the operator specifies,
     * not something that can be guessed from the environment.
     *
     * Used by `FileController::getAction()` for the redirect to `data/files/…`.
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
