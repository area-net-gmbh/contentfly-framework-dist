<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AccessTokenAuthenticator;

/**
 * Faehrt Symfonys `access_token`-Authenticator — ohne Firewall (013-002-0001).
 *
 * DAS FIREWALL-YAML AUS DEM STORY-UMFANG GIBT ES IN DIESEM BAUM NICHT. Kein
 * `config/`-Verzeichnis, kein SecurityBundle; der Kernel ist der eigene aus Epic `009` mit
 * `before()`-Hooks je Provider. Das ist kein Hindernis, sondern nur ein anderer Weg zum selben
 * Ergebnis: `AccessTokenAuthenticator` ist eine gewoehnliche Klasse, die einen Handler, einen
 * Extractor und optional einen Benutzerlader entgegennimmt.
 *
 * DREI ZEILEN SIND DER GANZE MECHANISMUS:
 *
 *   `supports()`      fragt den Extractor, ob ueberhaupt ein Token im Request steht
 *   `authenticate()`  laesst den Handler ein `UserBadge` bauen und packt es in einen Passport
 *   `getUser()`       loest das Badge auf — ueber den Lader des Badges oder den Benutzerlader
 *
 * Die Maschinerie darum — `AuthenticatorManager`, `FirewallMap`, `TokenStorage`, die
 * `kernel.request`-Listener — bleibt aussen vor. Sie traegt Zustand ueber Sitzungen und
 * mehrere Firewalls; Contentfly hat weder das eine noch das andere.
 *
 * JEDER FEHLSCHLAG SIEHT GLEICH AUS. Gefangen wird `AuthenticationException`, die gemeinsame
 * Oberklasse — kein Token, unbekannte Kennung, gesperrter Benutzer, abgelaufenes oder
 * manipuliertes Token muenden alle in `null`. Wer hier nach Ursachen unterscheidet, sagt dem
 * Aufrufer, welche Tokenart erwartet wird und welche Konten es gibt.
 *
 * VERDRAHTET SEIT 013-002-0004: `BaseControllerProvider::anmelden()` ruft ihn, und
 * `checkToken()` gibt es nicht mehr.
 */
final class Anmeldetreiber
{
    private AccessTokenAuthenticator $authenticator;

    public function __construct(
        AccessTokenHandlerInterface $handler,
        AccessTokenExtractorInterface $extractor,
        ?UserProviderInterface $benutzerlader = null,
    ) {
        $this->authenticator = new AccessTokenAuthenticator($handler, $extractor, $benutzerlader);
    }

    /**
     * Der Benutzer hinter dem Token dieses Requests — oder null.
     */
    public function benutzer(Request $request): ?UserInterface
    {
        /*
         * `=== false` und nicht `!`: `supports()` liefert **null**, wenn ein Token da ist —
         * die Kennzeichnung fuer „vielleicht, entscheide spaeter". Nur `false` heisst
         * „gar kein Token". Ein `!$this->authenticator->supports(...)` wuerde beide Faelle
         * gleich behandeln und damit jeden Request abweisen.
         */
        if ($this->authenticator->supports($request) === false) {
            return null;
        }

        try {
            return $this->authenticator->authenticate($request)->getUser();
        } catch (AuthenticationException) {
            return null;
        }
    }
}
