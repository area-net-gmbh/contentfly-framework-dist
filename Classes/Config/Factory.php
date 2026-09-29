<?php
namespace Areanet\PIM\Classes\Config;

use Areanet\PIM\Classes\Config;


/**
 * Class Factory
 *
 * Factory class to manage config setting for different server hosts (local, development, production,...)
 *
 * @package Areanet\PIM\Classes\Config
 */

class Factory{

    /**
     * @var Factory
     */
    protected static $_instance = null;

    /**
     * @var Config[]
     */
    protected $configSettings = array();


    /**
     * @param string $host Hostname
     * @return Config
     */
    public function getConfig($host = 'default')
    {

        if(!isset($this->configSettings[$host])){

            return $this->configSettings['default'];
        }

        return $this->configSettings[$host];
    }

    /**
     * Whether a configuration has been set at all (000-000-0024).
     *
     * `getConfig()` cannot be asked that: without a default it reads an undefined key and returns
     * null. `Kernel\Start` needs the answer when the start fails before `custom/config.php` ran.
     */
    public function hasConfig(): bool
    {
        return isset($this->configSettings['default']);
    }

    /**
     * Which config block this process runs under — decided by the DEPLOYMENT (015-000-0017).
     *
     * ── What this replaces ────────────────────────────────────────────────────────────────
     *
     * The block used to be picked with `$_SERVER['SERVER_NAME']`. Under Apache's default
     * `UseCanonicalName Off`, and under PHP's built-in server, that value IS the client's `Host`
     * header. So the caller chose which block answered their request — `APP_DEBUG`,
     * `APP_HTTP_AUTH_*`, `APP_FORCE_SSL`, `DB_*`, `SECURITY_*`, all of it — and an unknown host
     * got `default` without a word. Where a project follows the documented pattern (a relaxed
     * `default` for local work, a strict block per host), `Host: anything` handed out the
     * development configuration: stack traces, no HTTP basic lock, no forced SSL.
     *
     * ── What decides now ──────────────────────────────────────────────────────────────────
     *
     * `CONTENTFLY_CONFIG`, set by whoever deploys the instance. Nothing from the request reaches
     * this decision any more.
     *
     * ── Fail closed, in both directions ───────────────────────────────────────────────────
     *
     * A name that matches no block aborts the start. And so does a MISSING name while host
     * blocks exist: falling back to `default` there is exactly the silent downgrade this task is
     * about, and it would be invisible — the instance would run, just with the wrong settings.
     *
     * An installation with only a `default` block — the shipped template is one — needs nothing:
     * there is only one block, and no ambiguity to resolve.
     *
     * @param string|null $requested the value of `CONTENTFLY_CONFIG`, or null when it is unset
     * @throws \RuntimeException when the deployment has not said which block to use
     */
    public function chooseBlock(?string $requested): string
    {
        $requested  = is_string($requested) ? trim($requested) : '';
        $hostBlocks = array_values(array_diff(array_keys($this->configSettings), array('default')));

        if ($requested !== '') {
            if (isset($this->configSettings[$requested])) {
                return $requested;
            }

            throw new \RuntimeException(sprintf(
                'CONTENTFLY_CONFIG names the configuration block "%s", which custom/config.php '
                .'does not define. Defined blocks: %s. Refusing to fall back to "default" — that '
                .'fallback is what 015-000-0017 removed.',
                $requested,
                $this->configSettings === array() ? '(none)' : implode(', ', array_keys($this->configSettings))
            ));
        }

        if ($hostBlocks !== array()) {
            throw new \RuntimeException(sprintf(
                'custom/config.php defines the host blocks %s besides "default", but the '
                .'environment variable CONTENTFLY_CONFIG does not say which one this instance '
                .'runs under. Until 015-000-0017 the Host header of the request decided that, '
                .'which let a caller pick the block. Set CONTENTFLY_CONFIG in the deployment.',
                implode(', ', $hostBlocks)
            ));
        }

        return 'default';
    }

    /**
     * @param Config $config Config-Settings
     */
    public function setConfig(Config $config): void
    {
        $host = $config->getHost() ? $config->getHost() : 'default';
        $this->configSettings[$host] = $config;

    }


    /**
     * Get singleton instance
     * @return Factory;
     */
    public static function getInstance()
    {
        if(self::$_instance === null){
            self::$_instance = new Factory();
        }

        return self::$_instance;
    }


    /**
     * Disable cloning
     */
    protected function __clone(){}

    /**
     * Disable creating manual instances
     */
    protected function __construct(){}


}