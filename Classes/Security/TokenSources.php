<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\AccessToken\ChainAccessTokenExtractor;
use Symfony\Component\Security\Http\AccessToken\HeaderAccessTokenExtractor;
use Symfony\Component\Security\Http\AccessToken\QueryAccessTokenExtractor;

/**
 * Where a token may come from — and in which order (013-002-0002).
 *
 * FIVE SOURCES, FOUR OF THEM INHERITED. RFC 6750 only knows the first; the other four are what
 * `BaseControllerProvider::checkToken()` read until `013-002-0004`. Without them every existing
 * Ionic client breaks on update — existing projects send `appcms-token`, not
 * `Authorization: Bearer`. Epic `007` decides how long they keep being accepted.
 *
 * THE ORDER IS ADOPTED, NOT CHOSEN ANEW — and it is not the one you would expect. `checkToken()`
 * contained `$request->headers->get(TOKEN_HEADER_KEY_ALT, $tokenParameter)`: `X-XSRF-TOKEN` is the
 * value, `_token` only its default. So the header beats the parameter instead of coming after it.
 * Whoever turns that around when rebuilding it silently changes the result for every client that
 * sends both.
 *
 * WHY THE LEGACY HEADERS DO NOT GO THROUGH SYMFONY'S `HeaderAccessTokenExtractor`: it checks the
 * value against `[a-zA-Z0-9\-_+~\/.]+=*`. That is correct for a bearer token per RFC 6750, but for
 * the legacy sources it would be a silent narrowing: `checkToken()` took the header as it came,
 * and a project may choose its API token freely via `addToken`. A token with a character outside
 * that set would suddenly no longer be recognised — and nobody would get to see why.
 */
final class TokenSources
{
    public const HEADER_ALT      = 'appcms-token';
    public const HEADER_ALT_XSRF = 'X-XSRF-TOKEN';
    public const PARAMETER_ALT   = '_token';

    /**
     * The finished chain, in the order in which it is searched.
     *
     * `ChainAccessTokenExtractor` takes the first non-empty hit.
     */
    public static function chain(): AccessTokenExtractorInterface
    {
        return new ChainAccessTokenExtractor(self::sources());
    }

    /**
     * @return list<AccessTokenExtractorInterface>
     */
    public static function sources(): array
    {
        return array(
            // 1. RFC 6750 — the way everything is heading.
            new HeaderAccessTokenExtractor(),

            // 2.–5. The legacy sources, in the order from checkToken().
            new RawHeaderExtractor(self::HEADER_ALT),
            new RawHeaderExtractor(self::HEADER_ALT_XSRF),
            new QueryAccessTokenExtractor(self::PARAMETER_ALT),
            new BodyExtractor(self::PARAMETER_ALT),
        );
    }
}
