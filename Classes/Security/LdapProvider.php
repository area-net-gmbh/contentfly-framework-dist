<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Login against an LDAP or Active Directory (013-005-0001).
 *
 * It fulfils the contract from `013-004-0001` and nothing else: it verifies against the directory and
 * returns an `ExternalIdentity`. Creating users, mapping groups and issuing tokens is done by the
 * framework.
 *
 * ── SEARCH, THEN BIND — and why not the direct bind ───────────────────────────────────
 *
 * The direct approach builds the DN from the identifier (`uid=<identifier>,ou=…`) and binds with it.
 * It needs no service account and is therefore tempting. But it only works as long as all users sit
 * flat in one OU — and in Active Directory they do not: there they hang in nested organisational
 * units, and login uses `sAMAccountName`, which does not appear in the DN at all. A framework that can
 * only do the simple case is useless for the case this is about.
 *
 * So: bind with the service account, search for the user, then bind a second time with THEIR DN and
 * THEIR password. Where the directory allows an anonymous search, the service account stays empty.
 *
 * ── An empty password is rejected before anything happens ────────────────────────────
 *
 * THIS IS NOT POLITENESS BUT THE MOST IMPORTANT LINE HERE. LDAP has the "unauthenticated bind": a bind
 * with a valid DN and an EMPTY password counts as successful — it means "I do not want to log in", not
 * "the password is correct". Whoever reads the result of that bind as a login lets in anyone whose
 * identifier they know. It is one of the oldest mistakes in LDAP integrations.
 *
 * ── Every failure looks the same ──────────────────────────────────────────────────────
 *
 * Unknown identifier, wrong password, directory unreachable, expired service account — all `null`.
 * The caller only learns that it was not enough; whether the directory answers is none of their
 * business.
 */
final class LdapProvider implements LoginProvider, UserExistenceCheck
{
    /**
     * @param array{base_dn: string, filter: string, group_attribute: string,
     *              search_dn: ?string, search_password: ?string} $settings
     */
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly array $settings,
    ) {
    }

    /**
     * Builds the provider from the configuration.
     *
     * Separate from the constructor so that the provider can receive an `LdapInterface` for tests
     * without a directory running.
     */
    public static function fromConfig(): self
    {
        /*
         * `symfony/ldap` IS NOT IN `require` (013-005-0004).
         *
         * The package requires the system extension `ext-ldap`. If it were in `require`, EVERY
         * Contentfly installation would have to bring it — including those that never touch a
         * directory — because `composer install` checks the platform requirements of all packages.
         * Found during the gate run on PHP 8.4: `composer install` failed in the CI image with
         * "requires ext-ldap", and the job died silently.
         *
         * It therefore lives in `require-dev` (the framework tests the provider) and in `suggest`.
         * A project that uses it adds it itself.
         */
        if (!class_exists(Ldap::class)) {
            throw new \RuntimeException(
                'LdapProvider requires symfony/ldap. The package is deliberately not in the root '
                .'manifest because it requires the system extension ext-ldap: '
                .'`composer require symfony/ldap` in the project, and ext-ldap in the PHP image.'
            );
        }

        $config = Adapter::getConfig();

        return new self(
            Ldap::create('ext_ldap', array(
                'host'       => (string) $config->SECURITY_LDAP_HOST,
                'port'       => (int) $config->SECURITY_LDAP_PORT,
                'encryption' => (string) $config->SECURITY_LDAP_ENCRYPTION,
            )),
            array(
                'base_dn'         => (string) $config->SECURITY_LDAP_BASE_DN,
                'filter'          => (string) $config->SECURITY_LDAP_FILTER,
                'group_attribute' => (string) $config->SECURITY_LDAP_GROUP_ATTRIBUTE,
                'search_dn'       => $config->SECURITY_LDAP_SEARCH_DN,
                'search_password' => $config->SECURITY_LDAP_SEARCH_PASSWORD,
            )
        );
    }

    public function authenticate(Request $request): ?ExternalIdentity
    {
        $data       = $request->request->all();
        $identifier = $data['alias'] ?? null;
        $password   = $data['pass'] ?? null;

        if (!is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        /*
         * SEE THE CLASS COMMENT: an empty password is an unauthenticated bind and counts as successful
         * in the directory. Here it is a rejection.
         */
        if (!is_string($password) || $password === '') {
            return null;
        }

        try {
            $entry = $this->search($identifier);

            if (!$entry instanceof Entry) {
                return null;
            }

            // The second bind — with the DN from the directory, not a constructed one.
            $this->ldap->bind($entry->getDn(), $password);

            return new ExternalIdentity($identifier, $this->groups($entry));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The first bind and the search.
     *
     * Exactly ONE match counts. Two matches mean the filter is not unique — and guessing which one was
     * meant would be the worst of all answers.
     */
    private function search(string $identifier): ?Entry
    {
        $serviceAccount = $this->settings['search_dn'];

        if (is_string($serviceAccount) && $serviceAccount !== '') {
            $this->ldap->bind($serviceAccount, (string) $this->settings['search_password']);
        } else {
            // Anonymous search, where the directory allows it.
            $this->ldap->bind();
        }

        /*
         * ESCAPED, AND AS A FILTER.
         *
         * Without `escape()` an identifier such as `*` or `admin)(|(objectClass=*` rewrites the filter —
         * LDAP injection, the same pattern as SQL injection and just as old.
         */
        $filter = str_replace(
            '{identifier}',
            $this->ldap->escape($identifier, '', LdapInterface::ESCAPE_FILTER),
            (string) $this->settings['filter']
        );

        $matches = $this->ldap->query((string) $this->settings['base_dn'], $filter)->execute();

        if (count($matches) !== 1) {
            return null;
        }

        $entry = $matches[0];

        return $entry instanceof Entry ? $entry : null;
    }

    /**
     * Does the directory still know this identifier? (013-005-0002)
     *
     * Without a password — this is not about a login but about existence. The bind uses the service
     * account only, and the search uses the same filter as the login.
     *
     * `null` MEANS "I CANNOT TELL RIGHT NOW". Every exception ends here, and the sync then touches
     * nobody. An unreachable directory must not look like a deleted user — otherwise a network error
     * locks out the whole workforce.
     */
    public function knowsIdentifier(string $identifier): ?bool
    {
        if (trim($identifier) === '') {
            return false;
        }

        try {
            return $this->search($identifier) instanceof Entry;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What the directory reports as groups — unchanged.
     *
     * They are mapped by `GroupMapping` (013-004-0003). Rewriting anything here would mean having the
     * mapping in two places.
     *
     * @return list<string>
     */
    private function groups(Entry $entry): array
    {
        $values = $entry->getAttribute((string) $this->settings['group_attribute'], false);

        if (!is_array($values)) {
            return array();
        }

        $groups = array();

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $groups[] = $value;
            }
        }

        return $groups;
    }
}
