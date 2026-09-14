<?php
namespace Areanet\PIM\Classes\Security;

/**
 * The allowlist of login providers (013-004-0001).
 *
 * WHY IT EXISTS. Until now the class name came in as the request parameter `loginManager` and was
 * resolved to `Custom\Classes\<Name>`. The prefix and an `instanceof` check limited the damage — but
 * the choice lay with the caller. **Which class an application instantiates is a decision for the
 * operator.**
 *
 * The difference is not cosmetic: "the caller says what gets loaded" becomes "the caller chooses from
 * what the operator has approved". A name nobody registered does not exist — and a class name is such
 * a name.
 *
 * THE ENTRIES ARE LAZY. A provider may open a connection to an external system; that must not happen
 * on every request, only when someone asks for it. `register()` therefore also accepts a closure.
 */
final class LoginProviderRegistry
{
    /** @var array<string, LoginProvider|callable(): LoginProvider> */
    private array $entries = array();

    /** @var array<string, LoginProvider> */
    private array $built = array();

    /**
     * @param LoginProvider|callable(): LoginProvider $provider
     */
    public function register(string $name, $provider): void
    {
        $name = $this->normalise($name);

        if ($name === '') {
            throw new \InvalidArgumentException('A login provider needs a name.');
        }

        /*
         * A SECOND ENTRY UNDER THE SAME NAME IS REJECTED.
         *
         * Silently overwriting would mean that the order of two lines in `custom/app.php` decides
         * which external system is checked against. Nobody notices that until it does the wrong
         * thing.
         */
        if (isset($this->entries[$name])) {
            throw new \LogicException(
                'A login provider named "'.$name.'" is already registered. Names must be unique, '
                .'otherwise the order of registration decides.'
            );
        }

        if (!$provider instanceof LoginProvider && !is_callable($provider)) {
            throw new \InvalidArgumentException(
                'A login provider is either a LoginProvider instance or a closure that returns one.'
            );
        }

        $this->entries[$name] = $provider;
    }

    public function has(?string $name): bool
    {
        return $name !== null && isset($this->entries[$this->normalise($name)]);
    }

    /**
     * The provider for this name — or null.
     *
     * An unknown name returns null and no exception: it comes from the request, and a caller who
     * guesses should see nothing different from any other failure.
     */
    public function get(?string $name): ?LoginProvider
    {
        if (!$this->has($name)) {
            return null;
        }

        $name = $this->normalise((string) $name);

        if (!isset($this->built[$name])) {
            $entry    = $this->entries[$name];
            $provider = $entry instanceof LoginProvider ? $entry : $entry();

            if (!$provider instanceof LoginProvider) {
                throw new \LogicException(
                    'The closure for the login provider "'.$name.'" does not return a '
                    .LoginProvider::class.'.'
                );
            }

            $this->built[$name] = $provider;
        }

        return $this->built[$name];
    }

    /**
     * The registered names.
     *
     * For diagnostics and tests — NOT for an API response: which external systems an installation
     * knows is none of an unauthenticated caller's business.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Upper and lower case should not decide.
     *
     * A project registers `LDAP`, a client sends `ldap` — turning that into two different things
     * would be a trap without any benefit.
     */
    private function normalise(string $name): string
    {
        return strtolower(trim($name));
    }
}
