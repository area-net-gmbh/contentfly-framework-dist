<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Liest einen Parameter aus dem Rumpf.
 *
 * NICHT SYMFONYS `FormEncodedBodyExtractor`: Der verlangt `application/x-www-form-urlencoded`
 * und POST. Contentfly schickt `application/json`, und der Rumpf steht erst im `request`-Beutel,
 * nachdem der `before()`-Hook aus `BaseControllerProvider` ihn dorthin dekodiert hat. Genau von
 * dort liest `checkToken()` ihn heute auch.
 */
final class RumpfExtractor implements AccessTokenExtractorInterface
{
    public function __construct(
        private readonly string $parameter,
    ) {
    }

    public function extractAccessToken(Request $request): ?string
    {
        $wert = $request->request->get($this->parameter);

        return (is_string($wert) && $wert !== '') ? $wert : null;
    }
}
