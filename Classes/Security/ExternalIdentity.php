<?php
namespace Areanet\PIM\Classes\Security;

/**
 * What a login provider brings back from the external system (013-004-0001).
 *
 * THIS IS THE WHOLE INTERFACE TO THE PROJECT. A provider verifies against its external system and
 * then says: "This is the user, and this is what the system says about them." Everything else —
 * finding or creating the user, setting groups, issuing the token — is done by the framework.
 *
 * THIS SEPARATION DID NOT EXIST BEFORE: `LoginManager::auth()` had to return a finished `User`
 * entity and call `createManagedUser()` for it. Provisioning therefore lived in the project — each
 * one on its own, each with its own mistakes. A provider no longer has anything to do with the
 * database.
 *
 * IT IS IMMUTABLE. A value the login could still change along the way would be a way in: a hook
 * rewriting `identifier` would log in somebody else.
 */
final class ExternalIdentity
{
    /**
     * @param string              $identifier The user's identifier IN THE EXTERNAL SYSTEM — not the
     *                                        Contentfly alias. It is stored in readable form.
     * @param list<string>        $groups     What the external system reports as groups, unchanged.
     *                                        The framework maps them to Contentfly groups
     *                                        (013-004-0003).
     * @param array<string,mixed> $attributes Anything else the external system provides.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly array $groups = array(),
        public readonly array $attributes = array(),
    ) {
        if (trim($identifier) === '') {
            throw new \InvalidArgumentException(
                'An external identity without an identifier does not exist. A provider that '
                .'recognised nobody returns null instead of an empty identifier.'
            );
        }
    }
}
