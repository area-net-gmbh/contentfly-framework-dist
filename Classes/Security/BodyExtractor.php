<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Reads a parameter from the request body.
 *
 * NOT SYMFONY'S `FormEncodedBodyExtractor`: that one requires `application/x-www-form-urlencoded`
 * and POST. Contentfly sends `application/json`, and the body only sits in the `request` bag after
 * the `before()` hook from `BaseControllerProvider` has decoded it there. That is exactly where
 * `checkToken()` read it from as well.
 */
final class BodyExtractor implements AccessTokenExtractorInterface
{
    public function __construct(
        private readonly string $parameter,
    ) {
    }

    public function extractAccessToken(Request $request): ?string
    {
        $value = $request->request->get($this->parameter);

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
