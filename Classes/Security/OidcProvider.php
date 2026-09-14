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
 * ── Every failure looks the same ──────────────────────────────────────────────────────
 *
 * Rejected token, response without the expected identifier, provider unreachable — all `null`.
 */
final class OidcProvider implements LoginProvider
{
    /**
     * @param array{endpoint: string, identifier_claim: string, groups_claim: string} $settings
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $settings,
    ) {
    }

    public static function fromConfig(): self
    {
        $config = Adapter::getConfig();

        return new self(
            HttpClient::create(array('timeout' => 5)),
            array(
                'endpoint'         => (string) $config->SECURITY_OIDC_USERINFO_ENDPOINT,
                'identifier_claim' => (string) $config->SECURITY_OIDC_IDENTIFIER_CLAIM,
                'groups_claim'     => (string) $config->SECURITY_OIDC_GROUPS_CLAIM,
            )
        );
    }

    public function authenticate(Request $request): ?ExternalIdentity
    {
        $token = $this->token($request);

        if ($token === null || $this->settings['endpoint'] === '') {
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
