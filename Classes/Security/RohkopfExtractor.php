<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Liest einen Header, wie er kommt — ohne Muster und ohne Praefix.
 *
 * Siehe `Tokenquellen`: Symfonys `HeaderAccessTokenExtractor` prueft den Wert gegen ein Muster.
 * Fuer die Altquellen waere das eine stille Verengung gegenueber `checkToken()`.
 */
final class RohkopfExtractor implements AccessTokenExtractorInterface
{
    public function __construct(
        private readonly string $kopfzeile,
    ) {
    }

    public function extractAccessToken(Request $request): ?string
    {
        $wert = $request->headers->get($this->kopfzeile);

        return (is_string($wert) && $wert !== '') ? $wert : null;
    }
}
