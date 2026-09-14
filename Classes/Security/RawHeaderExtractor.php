<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Reads a header as it comes — without a pattern and without a prefix.
 *
 * See `TokenSources`: Symfony's `HeaderAccessTokenExtractor` checks the value against a pattern.
 * For the legacy sources that would be a silent narrowing compared to `checkToken()`.
 */
final class RawHeaderExtractor implements AccessTokenExtractorInterface
{
    public function __construct(
        private readonly string $header,
    ) {
    }

    public function extractAccessToken(Request $request): ?string
    {
        $value = $request->headers->get($this->header);

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
