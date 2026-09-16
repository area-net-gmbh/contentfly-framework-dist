<?php
namespace Areanet\PIM\Classes;

/**
 * The one response shape of the API: `data`, `errors`, `meta` (`011-001-0003`).
 *
 * WHY THIS IS A CLASS AND NOT TWO PIECES OF CODE. Success and failure are built in two different
 * places — `ApiController::renderResponse()` and the error handler in `bootstrap-web.php` — and
 * they have to produce the same hull, or the unification is worth nothing: a client that reads
 * `body.errors` before it knows what it got must find the same keys either way. Two copies of the
 * meta drift; one class does not.
 *
 * `data` and `errors` are BOTH always present. On success `errors` is `null`, on failure `data` is
 * `null`. That is the point — the caller can look at `errors` without first deciding which kind of
 * answer it has in its hands.
 *
 * The shape is decided in `an_project/docs/api-envelope.md`.
 */
final class Envelope
{
    /**
     * @param mixed $data the payload
     * @param array<string,mixed> $meta what the endpoint adds to the standard meta
     * @return array{data:mixed,errors:null,meta:array<string,mixed>}
     */
    public static function success(mixed $data, ?string $hash, array $meta = array()): array
    {
        return array(
            'data'   => $data,
            'errors' => null,
            'meta'   => self::meta($hash, $meta),
        );
    }

    /**
     * @param list<array<string,mixed>> $errors one entry per fault — see entry()
     * @param array<string,mixed> $meta
     * @return array{data:null,errors:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public static function failure(array $errors, ?string $hash, array $meta = array()): array
    {
        return array(
            'data'   => null,
            'errors' => $errors,
            'meta'   => self::meta($hash, $meta),
        );
    }

    /**
     * One entry of the `errors` list, built from one throwable.
     *
     * FOUR KEYS, ALWAYS PRESENT — that is the difference from the shape this replaces. Before, the
     * body carried `message_value` only for a `ContentflyException` and `message_entity`/
     * `message_lang` only for a `ContentflyI18NException`; which keys arrived depended on which
     * exception had been thrown, so a client had to know the exception classes to read the answer.
     *
     * - `code` is the identifier a client BRANCHES ON: the `Messages` constant of a Contentfly
     *   exception — and `null` for everything else. The null is not a gap, it is the statement:
     *   this is an unforeseen server fault, there is nothing stable here to decide on. Whoever
     *   needs to distinguish those looks at `type`, and takes the risk that a class gets renamed.
     * - `detail` is `getMessage()` and is meant for a human. For a Contentfly exception that is
     *   the same string as `code`, because the message IS the key there — the redundancy is the
     *   price for the rule "branch on `code`, show `detail`" holding without exception.
     * - `type` is the exception class, unchanged from the previous body.
     * - `context` carries what the exception knows beyond its code: the rejected value, or entity
     *   and language. `null` where there is nothing.
     *
     * The status code is NOT in here. It is in the HTTP response, and `000-000-0006` put it right
     * there; a body that repeats it invites the two to disagree.
     *
     * @return array{code:?string,detail:string,type:string,context:?array<string,mixed>}
     */
    public static function entry(\Throwable $e): array
    {
        if ($e instanceof Exceptions\ContentflyException) {
            return self::fault($e->getMessage(), $e->getMessage(), get_class($e), array('value' => $e->getValue()));
        }

        if ($e instanceof Exceptions\ContentflyI18NException) {
            return self::fault($e->getMessage(), $e->getMessage(), get_class($e),
                array('entity' => $e->getEntity(), 'lang' => $e->getLang()));
        }

        return self::fault(null, $e->getMessage(), get_class($e));
    }

    /**
     * One entry, without a throwable.
     *
     * Not every fault comes from an exception — the guard that reports an uninstalled Contentfly
     * never throws one. It answers in the same shape nonetheless, and this is the method that
     * makes that cheap enough that nobody builds the four keys by hand.
     *
     * @param array<string,mixed>|null $context
     * @return array{code:?string,detail:string,type:string,context:?array<string,mixed>}
     */
    public static function fault(?string $code, string $detail, string $type, ?array $context = null): array
    {
        return array(
            'code'    => $code,
            'detail'  => $detail,
            'type'    => $type,
            'context' => $context,
        );
    }

    /**
     * The schema hash — or `null`, if it cannot be had.
     *
     * THE ERROR PATH MUST NOT DIE OF ITS OWN META. `$app['schema']` builds the schema on first
     * access, over all entities; if that is what went wrong, reading it here would throw inside
     * the error handler. That is precisely the failure `000-000-0006` fixed in another place —
     * the handler died on `$app['request']` and Symfony's HTML page came out instead of this
     * application's JSON. So the hash is a best effort, and its absence costs a meta field, not
     * the answer.
     *
     * @param \ArrayAccess<string,mixed>|null $app
     */
    public static function schemaHash(?\ArrayAccess $app): ?string
    {
        if ($app === null) {
            return null;
        }

        try {
            $schema = $app['schema'];

            return isset($schema['_hash']) ? (string) $schema['_hash'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `ts`, the two versions, `hash` — and then whatever the endpoint adds.
     *
     * `version` is the FRAMEWORK version and `projectVersion` the project's. Until `011-001-0002`
     * there was one field for both, and `/api/config` lost the project version in it.
     *
     * BOTH ARE READ DEFENSIVELY, and that is not decoration: a start that fails in `Kernel\Start`
     * answers before `version.php` has been read, so the constants do not exist yet. A constant
     * lookup would then be a fatal error inside the error response — the failure mode of
     * `000-000-0006`, one floor further down. `null` is the honest answer there: at that point
     * nobody knows which version could not start.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function meta(?string $hash, array $extra): array
    {
        $now = new \DateTime();

        return array_merge(array(
            'ts'             => $now->format('Y-m-d H:i:s'),
            'version'        => defined('APP_VERSION') ? APP_VERSION : null,
            'projectVersion' => defined('CUSTOM_VERSION') ? CUSTOM_VERSION : null,
            'hash'           => $hash,
        ), $extra);
    }
}
