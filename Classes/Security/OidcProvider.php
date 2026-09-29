<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Login through an OIDC provider, verified at the userinfo endpoint (013-005-0003).
 *
 * The client obtains its access token from the identity provider and presents it here. Contentfly
 * asks the userinfo endpoint: if it answers with the user data, the token is valid; if it answers with
 * an error, it is not.
 *
 * ── Why the userinfo approach and not local verification ─────────────────────────────
 *
 * Symfony brings both. Measured on 2026-09-11:
 *
 *   locally against JWKS   5 packages, including web-token/jwt-library and spomky-labs/pki-framework.
 *                          Fast, survives an outage of the provider — but a revocation only takes
 *                          effect when the token expires.
 *   userinfo               3 lightweight packages. A revocation takes effect immediately — but every
 *                          login depends on the provider.
 *
 * Decided: userinfo. **What puts the trade-off into perspective and belongs to it:** after login
 * Contentfly issues its OWN token (013-003). The OIDC token is verified exactly once, at login; after
 * that only Contentfly's own token counts. The revocation advantage therefore only concerns the window
 * between revocation and that one login attempt — and the outage disadvantage likewise only concerns
 * the login, not the running session.
 *
 * ── Why not Symfony's OidcUserInfoTokenHandler ────────────────────────────────────────
 *
 * It is an `AccessTokenHandlerInterface` and returns a `UserBadge` — an identifier and nothing else.
 * **The groups would be lost**, and those are exactly what the contract from `013-004` needs so that
 * `GroupMapping` has something to map. A wrapper would have to ask the endpoint a second time. The own
 * call is shorter than that detour.
 *
 * ── The token has to have been issued FOR THIS application (015-000-0015) ─────────────
 *
 * The userinfo endpoint answers "valid token, that user". It does NOT answer "issued for you".
 * Until `015-000-0015` nobody asked the second question, and so every other client of the same
 * provider could redeem its tokens here — a foreign site with a "sign in with <IdP>" button, a
 * compromised client in the same realm.
 *
 * The audience is therefore checked FIRST, by token introspection (RFC 7662), and only a token
 * the provider reports as active and as belonging to `client_id` reaches the userinfo call.
 *
 * Not the ID token: verifying that locally means signature, JWKS and a key cache — the five
 * packages `013-005-0003` weighed and rejected. Introspection is one more request on the client
 * that is already here, and it keeps what decided that story: the provider answers, so a
 * revocation takes effect at once.
 *
 * ── Every failure looks the same ──────────────────────────────────────────────────────
 *
 * Rejected token, token for another client, response without the expected identifier, provider
 * unreachable — all `null`.
 */
final class OidcProvider implements LoginProvider
{
    /**
     * @param array{endpoint: string, identifier_claim: string, groups_claim: string,
     *              introspection_endpoint: string, client_id: string, client_secret: string} $settings
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $settings,
    ) {
    }

    public static function fromConfig(): self
    {
        $config = Adapter::getConfig();

        /*
         * FAIL CLOSED — without the audience check the provider does not start (015-000-0015).
         *
         * The alternative would be to start and let every token through, which is the hole this
         * task closes; or to start and refuse every login, which looks like an outage and sends
         * whoever debugs it to the identity provider. Refusing to build says where to look.
         */
        $clientId     = (string) $config->SECURITY_OIDC_CLIENT_ID;
        $introspection = (string) $config->SECURITY_OIDC_INTROSPECTION_ENDPOINT;

        if (trim($clientId) === '' || trim($introspection) === '') {
            throw new \RuntimeException(
                'OidcProvider requires SECURITY_OIDC_CLIENT_ID and '
                .'SECURITY_OIDC_INTROSPECTION_ENDPOINT in custom/config.php. Without them the '
                .'provider cannot tell whether an access token was issued for this application, '
                .'and any client of the same identity provider could redeem its tokens here '
                .'(015-000-0015).'
            );
        }

        return new self(
            HttpClient::create(array('timeout' => 5)),
            array(
                'endpoint'               => (string) $config->SECURITY_OIDC_USERINFO_ENDPOINT,
                'identifier_claim'       => (string) $config->SECURITY_OIDC_IDENTIFIER_CLAIM,
                'groups_claim'           => (string) $config->SECURITY_OIDC_GROUPS_CLAIM,
                'introspection_endpoint' => $introspection,
                'client_id'              => $clientId,
                'client_secret'          => (string) $config->SECURITY_OIDC_CLIENT_SECRET,
            )
        );
    }

    public function authenticate(Request $request): ?ExternalIdentity
    {
        $token = $this->token($request);

        if ($token === null
            || $this->settings['endpoint'] === ''
            || $this->settings['introspection_endpoint'] === ''
            || $this->settings['client_id'] === ''
        ) {
            return null;
        }

        /*
         * The audience check comes FIRST, before the userinfo call (015-000-0015).
         *
         * Order matters: a token for another client must not even reach the endpoint that would
         * hand out this user's claims.
         */
        if (!$this->issuedForThisClient($token)) {
            return null;
        }

        try {
            $response = $this->client->request('GET', $this->settings['endpoint'], array(
                'headers' => array('Authorization' => 'Bearer '.$token),
            ));

            /*
             * The status code is checked EXPLICITLY.
             *
             * `toArray()` throws on 4xx and 5xx by itself — but only as long as nobody sets
             * `throw: false`. Relying on a default that an option can switch off is the wrong kind
             * of economy for a login.
             */
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $identifier = $data[$this->settings['identifier_claim']] ?? null;

        if (!is_string($identifier) && !is_int($identifier)) {
            return null;
        }

        $identifier = (string) $identifier;

        if (trim($identifier) === '') {
            return null;
        }

        return new ExternalIdentity($identifier, $this->groups($data));
    }

    /**
     * Asks the provider what the token is, and whether it was issued for this client (RFC 7662).
     *
     * Everything that is not a clear yes is a no: a non-200, `active` that is not exactly `true`,
     * an answer that names another client — and an answer that names none at all. The last one is
     * the case worth spelling out: `client_id` and `aud` are both OPTIONAL in RFC 7662, so a
     * provider may legitimately answer without either. Contentfly still refuses, because the
     * question it asked was not answered, and treating "did not say" as "yes" is the bug.
     */
    private function issuedForThisClient(string $token): bool
    {
        $options = array(
            'body' => array('token' => $token, 'token_type_hint' => 'access_token'),
        );

        if ($this->settings['client_secret'] !== '') {
            $options['auth_basic'] = array(
                $this->settings['client_id'],
                $this->settings['client_secret'],
            );
        }

        try {
            $response = $this->client->request(
                'POST',
                $this->settings['introspection_endpoint'],
                $options
            );

            // Checked explicitly, for the same reason as at the userinfo endpoint below.
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = $response->toArray(false);
        } catch (\Throwable) {
            return false;
        }

        if (($data['active'] ?? null) !== true) {
            return false;
        }

        return $this->namesThisClient($data);
    }

    /**
     * Whether the introspection response names this client — in `client_id` or in `aud`.
     *
     * `aud` is a string or a list of strings; both forms are in the wild, and both are read.
     *
     * @param array<string, mixed> $data
     */
    private function namesThisClient(array $data): bool
    {
        $expected = $this->settings['client_id'];

        if (($data['client_id'] ?? null) === $expected) {
            return true;
        }

        $audience = $data['aud'] ?? null;

        if (is_string($audience)) {
            return $audience === $expected;
        }

        if (is_array($audience)) {
            return in_array($expected, $audience, true);
        }

        return false;
    }

    /**
     * The access token from the request.
     *
     * `accessToken` is the descriptive name; `pass` is read as well, because a client that already
     * sends a login form to `/auth/login` can use the same field as for a password — and the login
     * throttle (013-001-0003) applies to the same request anyway.
     */
    private function token(Request $request): ?string
    {
        $data = $request->request->all();

        foreach (array('accessToken', 'pass') as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * What the provider reports as groups — unchanged.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function groups(array $data): array
    {
        $field = $this->settings['groups_claim'];

        if ($field === '' || !isset($data[$field]) || !is_array($data[$field])) {
            return array();
        }

        $groups = array();

        foreach ($data[$field] as $value) {
            if (is_string($value) && $value !== '') {
                $groups[] = $value;
            }
        }

        return $groups;
    }
}
