<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\AccessToken\ChainAccessTokenExtractor;
use Symfony\Component\Security\Http\AccessToken\HeaderAccessTokenExtractor;
use Symfony\Component\Security\Http\AccessToken\QueryAccessTokenExtractor;

/**
 * Woher ein Token kommen darf — und in welcher Reihenfolge (013-002-0002).
 *
 * FUENF QUELLEN, VIER DAVON GEERBT. RFC 6750 kennt nur die erste; die anderen vier sind das,
 * was `BaseControllerProvider::checkToken()` bis `013-002-0004` gelesen hat. Ohne sie bricht jeder bestehende
 * Ionic-Client beim Update — Bestandsprojekte schicken `appcms-token`, nicht
 * `Authorization: Bearer`. Wie lange sie mitlaufen, entscheidet Epic `007`.
 *
 * DIE REIHENFOLGE IST UEBERNOMMEN, NICHT NEU GEWAEHLT — und sie ist nicht die, die man vermutet.
 * In `checkToken()` steht `$request->headers->get(TOKEN_HEADER_KEY_ALT, $tokenParameter)`:
 * `X-XSRF-TOKEN` ist der Wert, `_token` nur dessen Vorgabe. Der Header schlaegt den Parameter
 * also, statt nach ihm zu kommen. Wer das beim Nachbauen umdreht, aendert fuer jeden Client,
 * der beides mitschickt, still das Ergebnis.
 *
 * WARUM DIE ALTEN HEADER NICHT UEBER SYMFONYS `HeaderAccessTokenExtractor` LAUFEN: Der prueft
 * den Wert gegen `[a-zA-Z0-9\-_+~\/.]+=*`. Fuer ein Bearer-Token nach RFC 6750 ist das richtig,
 * fuer die Altquellen waere es eine stille Verengung: `checkToken()` nimmt den Header, wie er
 * kommt, und ein Projekt darf sich seinen API-Token ueber `addToken` frei waehlen. Ein Token
 * mit einem Zeichen ausserhalb dieser Menge wuerde ab sofort nicht mehr erkannt — und niemand
 * bekaeme zu sehen, warum.
 */
final class Tokenquellen
{
    public const HEADER_ALT      = 'appcms-token';
    public const HEADER_ALT_XSRF = 'X-XSRF-TOKEN';
    public const PARAMETER_ALT   = '_token';

    /**
     * Die fertige Kette, in der Reihenfolge, in der gesucht wird.
     *
     * `ChainAccessTokenExtractor` nimmt den ersten nicht-leeren Treffer.
     */
    public static function kette(): AccessTokenExtractorInterface
    {
        return new ChainAccessTokenExtractor(self::quellen());
    }

    /**
     * @return list<AccessTokenExtractorInterface>
     */
    public static function quellen(): array
    {
        return array(
            // 1. RFC 6750 — der Weg, auf den alles zulaeuft.
            new HeaderAccessTokenExtractor(),

            // 2.–5. Die Altquellen, in der Reihenfolge aus checkToken().
            new RohkopfExtractor(self::HEADER_ALT),
            new RohkopfExtractor(self::HEADER_ALT_XSRF),
            new QueryAccessTokenExtractor(self::PARAMETER_ALT),
            new RumpfExtractor(self::PARAMETER_ALT),
        );
    }
}
