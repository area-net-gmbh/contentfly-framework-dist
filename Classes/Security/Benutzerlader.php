<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Laedt einen Benutzer ueber seine Kennung (013-002-0001).
 *
 * DER GEGENPART ZUM TOKENHANDLER. Ein Handler liefert ein `UserBadge` mit einer Kennung; wer
 * daraus einen Benutzer macht, ist dieser Lader. Er steht auch dann bereit, wenn ein Handler
 * den Benutzer bereits selbst geladen hat — `AccessTokenAuthenticator` setzt ihn nur ein, wenn
 * das Badge keinen eigenen Lader mitbringt.
 *
 * EIN GESPERRTER BENUTZER WIRD BEHANDELT WIE EIN UNBEKANNTER, mit derselben Ausnahme. Das ist
 * kein Versehen: Die Story verlangt, dass ein ungueltiges Token ununterscheidbar scheitert.
 * Wuerde hier eine eigene Ausnahme fuer „gesperrt" fliegen, waere die Antwort ein Orakel dafuer,
 * welche Konten es gibt und welche gerade abgeschaltet sind.
 *
 * NOCH NICHT VERDRAHTET. `BaseControllerProvider::checkToken()` bleibt bis `013-002-0004` die
 * einzige Autoritaet; dieser Lader steht daneben und wird fuer sich geprueft. Erst umschalten,
 * wenn beide Haelften stehen — dasselbe Muster wie in Epic `010`.
 */
final class Benutzerlader implements UserProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $benutzer = $this->em->getRepository(User::class)->findOneBy(array('alias' => $identifier));

        if (!$benutzer instanceof User || !$benutzer->getIsActive()) {
            throw new UserNotFoundException();
        }

        return $benutzer;
    }

    /**
     * Laedt den Benutzer aus der Datenbank nach.
     *
     * Gerufen wird das dort, wo ein Benutzer aus einer Sitzung wiederhergestellt wird. Contentfly
     * hat keine Sitzung — sie ist mit Story `012-004` entfallen —, weshalb der Weg hier nur der
     * Vollstaendigkeit halber steht. Er laedt ueber dieselbe Kennung nach und prueft damit
     * dieselben Bedingungen; ein zwischenzeitlich gesperrter Benutzer faellt heraus.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
