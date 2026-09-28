<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Symfony\Component\HttpFoundation\Request;

/**
 * THE `lang` PARAMETER, ONCE AND IN ONE SHAPE (015-000-0011).
 *
 * ── The finding ────────────────────────────────────────────────────────────────────────
 *
 * `lang` went from the request into two places that read it differently:
 *
 *   the rights check   `Group::langIsWritable()` looked the value up as an ARRAY KEY, so
 *                      case-sensitively — and an unknown key counted as unrestricted.
 *   the query          `a.lang = :lang` under `utf8mb3_unicode_ci`, which ignores case and
 *                      trailing spaces (PAD SPACE).
 *
 * So `EN`, `En` and `en ` passed the rights check as "no restriction configured" and still hit
 * the `en` row. A group limited to `{"en": "readable"}` could change, delete and create English
 * content — the restriction was one keystroke wide.
 *
 * ── The rule ───────────────────────────────────────────────────────────────────────────
 *
 * The value is trimmed and lower-cased at the edge, and it has to be one of `APP_LANGUAGES`.
 * Anything else is a 400, not a silently different language: an unknown code is a mistake in the
 * request, and answering it as "no restriction" is how this gap came about.
 *
 * ── Why a class and not twelve normalisations ──────────────────────────────────────────
 *
 * `ApiController` reads `lang` in twelve places. Whoever normalises each of them in turn forgets
 * one eventually, and one unnormalised entry point is the whole finding again. `LanguageEdgeTest`
 * therefore holds that no controller reads the parameter raw any more.
 */
final class Language
{
    /**
     * The value in the one shape everything else compares against.
     *
     * `mb_strtolower` and not `strtolower`: a language code is ASCII in practice, but the
     * parameter is free text from a request and the collation folds more than ASCII.
     */
    public static function normalise(mixed $lang): ?string
    {
        if (!is_scalar($lang)) {
            return null;
        }

        $lang = mb_strtolower(trim((string) $lang), 'UTF-8');

        return $lang === '' ? null : $lang;
    }

    /**
     * Is this one of the configured languages?
     *
     * WITHOUT `APP_LANGUAGES` THERE IS NOTHING TO CHECK AGAINST, and then every value counts as
     * known. That is deliberate: an installation with i18n entities and no configured languages
     * would otherwise be unable to write anything at all, and this task is about a rights check
     * that was too wide — not about closing i18n for installations that never configured it.
     */
    public static function isConfigured(?string $lang): bool
    {
        $configured = self::configured();

        if ($configured === array()) {
            return true;
        }

        return $lang !== null && in_array($lang, $configured, true);
    }

    /**
     * `APP_LANGUAGES`, normalised the same way — so the comparison above cannot fail on the
     * configuration's own spelling.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $configured = Adapter::getConfig()->APP_LANGUAGES;

        if (!is_array($configured)) {
            return array();
        }

        $normalised = array();
        foreach ($configured as $language) {
            if (($language = self::normalise($language)) !== null) {
                $normalised[] = $language;
            }
        }

        return $normalised;
    }

    /**
     * The request's `lang`, normalised and checked — or null when none was sent.
     *
     * Null stays null: `lang` is optional in most endpoints, and the caller decides what a
     * missing language means. What is refused is a value that was sent and is not a language.
     *
     * @throws ContentflyException 400 for a value that names no configured language
     */
    public static function fromRequest(Request $request): ?string
    {
        $raw = $request->request->all()['lang'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $lang = self::normalise($raw);

        if ($lang === null || !self::isConfigured($lang)) {
            throw new ContentflyException(
                Messages::contentfly_general_invalid_params,
                'lang',
                Messages::contentfly_status_bad_request
            );
        }

        return $lang;
    }
}
