<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * The framework's container — string keys and lazy factories (009-002-0002).
 *
 * IT REPRODUCES PIMPLE'S CONTRACT, AND DOES SO ON PURPOSE. `custom/app.php` registers a
 * project's services like this:
 *
 *     $app['my.service'] = function ($app) { return new MyService($app['orm.em']); };
 *
 * That is a **lazy factory**: it runs on first access, not on registration, and receives the
 * container as its argument to resolve its dependencies. Symfony's DI container only accepts
 * finished objects at runtime; a bridge in front of it would have to hold the closures itself
 * and would end up being this container. Decided on 2026-09-09.
 *
 * WHAT IT IS NOT: a complete Pimple replica. `share()`, `protect()`, `raw()`, `factory()` and
 * `register()` are missing — they do not occur in the tree. Whatever is missing is added when a
 * caller needs it, not on speculation.
 *
 * FREEZING IS REPRODUCED BECAUSE A GUARANTEE DEPENDS ON IT. As soon as a definition has been read
 * once, it counts as frozen, and `extend()` throws. The `ConsoleManager` depends on exactly this
 * order: it extends the dispatcher through `extend()` and has to do so before anyone reads it.
 * `tests/Unit/Manager/RouteAndConsoleManagerTest.php` has checked this since `008-004`, and
 * `000-000-0006` once tripped over it. A container that silently allowed it would hide the
 * mistake instead of reporting it.
 */
class Container implements \ArrayAccess
{
    /** @var array<string,mixed> Values and factories not yet resolved. */
    private array $entries = array();

    /** @var array<string,true> Keys whose definition has been read and is therefore frozen. */
    private array $frozen = array();

    /** @var array<string,true> Keys whose value is a factory. */
    private array $factories = array();

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->entries);
    }

    /**
     * Reads an entry and resolves a factory the first time.
     *
     * The result is kept: a factory runs exactly once, and every further access gets the same
     * object. That is what makes `$app['orm.em']` the same EntityManager everywhere.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->entries)) {
            throw new \InvalidArgumentException(sprintf('The container does not know "%s".', $offset));
        }

        $this->frozen[$offset] = true;

        if (!isset($this->factories[$offset])) {
            return $this->entries[$offset];
        }

        $factory = $this->entries[$offset];
        unset($this->factories[$offset]);

        return $this->entries[$offset] = $factory($this);
    }

    /**
     * Sets a value or a factory.
     *
     * A closure counts as a factory. That is Pimple's rule, and it has a downside: whoever wants
     * to store a closure as a *value* — a callback someone calls later — gets it resolved
     * instead. Pimple has `protect()` for that; it is missing here because nobody in the tree
     * stores a closure as a value. If it ever bites someone, the failure is loud: the caller gets
     * the return value instead of the closure.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->entries[$offset] = $value;
        unset($this->frozen[$offset]);

        if ($value instanceof \Closure) {
            $this->factories[$offset] = true;
        } else {
            unset($this->factories[$offset]);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->entries[$offset], $this->frozen[$offset], $this->factories[$offset]);
    }

    /**
     * Replaces a registered factory with one that wraps the previous one.
     *
     * The new closure receives the result of the previous one and the container. That is how the
     * `ConsoleManager` attaches its listeners to the dispatcher without building it itself.
     *
     * WITHOUT TYPE DECLARATIONS, AND THAT IS A DECISION. `ApplicationInterface::extend($id,
     * $callable)` does not declare them — the interface adopted Pimple's signature while Pimple
     * was still underneath. A typed implementation would therefore not satisfy it: PHP does not
     * allow an implementation to add parameter types.
     *
     * Updating the interface would technically not break anything — every caller passes
     * `(string, callable)` anyway. It stays unchanged nonetheless: `009-002-0002` has the
     * criterion that it stays word for word, and that criterion is a tripwire against quietly
     * reshaping the contract during the kernel switch. The gain from two type declarations does
     * not outweigh that. The types are therefore given in `@param`.
     *
     * @param string   $id
     * @param callable $callable
     *
     * @throws \RuntimeException if the key has already been read
     */
    public function extend($id, $callable)
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new \InvalidArgumentException(sprintf('The container does not know "%s".', $id));
        }

        if (isset($this->frozen[$id])) {
            throw new \RuntimeException(sprintf(
                'The service "%s" has already been read and can no longer be extended. '
                .'Whoever uses extend() has to do so before anyone touches the service.',
                $id
            ));
        }

        if (!isset($this->factories[$id])) {
            throw new \InvalidArgumentException(sprintf(
                'The entry "%s" is a value, not a factory — there is nothing to extend.',
                $id
            ));
        }

        $previous = $this->entries[$id];

        $this->entries[$id] = static function (Container $container) use ($previous, $callable) {
            return $callable($previous($container), $container);
        };
    }

    /** @return array<int,string> All known keys, in registration order. */
    public function keys(): array
    {
        return array_keys($this->entries);
    }
}
