<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A revoked access JWT, until it would have expired anyway (013-003-0003).
 *
 * THE REMAINING WINDOW, NOTHING MORE. The main part of revocation lives in the refresh model:
 * logout deletes the refresh row, and after that nobody gets a new access JWT any more. What
 * remains is the one token the client is currently holding — it is valid until its `exp`, and
 * that is exactly what this table is for.
 *
 * IT STAYS SMALL, because an entry expires together with the token. The customer project had
 * built such a list, but WITHOUT the refresh model alongside it — there it had to cover the full
 * token lifetime and grew without bound.
 *
 * WHAT IT IS NOT NEEDED FOR has been verified: since `013-002-0001` a user lock takes effect
 * immediately. The JWT branch returns its `UserBadge` without a loader of its own, so the
 * `UserLoader` loads the user from `pim_user` and rejects a locked one with the same exception
 * as an unknown one. Anyone who built this list for locking built something that already
 * exists.
 *
 * IT LIVES IN THE DATABASE AND NOT IN THE CACHE. A cleared cache must not undo a revocation —
 * and the cache is exactly what people clear when something is stuck.
 *
 * It does NOT extend `Base`: the fields there (`userCreated`, `isIntern`, GUID key) describe
 * content objects of the API. This table is infrastructure, like `pim_token`, and follows the
 * same lean form.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_revoked_token')]
#[ORM\Index(name: 'idx_revoked_token_expires', columns: ['expiresAt'])]
class RevokedToken
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * The `jti` of the revoked token.
     *
     * In plain text, and that is correct here: unlike a token, a `jti` is not a secret — it
     * opens nothing. Anyone who reads it learns that some token was revoked, and nothing else.
     * A hash would need a reason, and there is none.
     */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    protected $jti;

    /**
     * When the revoked token would have expired anyway.
     *
     * From then on the entry is moot; `appcms:token:cleanup` clears it away.
     *
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected $expiresAt;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    protected $created;

    /*
     * THERE USED TO BE A `modified` HERE THAT DID NOTHING (until 000-000-0028).
     *
     * `Classes/Events/LoadMetadata` attached an index on this column to EVERY entity, and
     * without it the installation failed — with a message that did not name the reason.
     * So this line fulfilled a requirement nobody had written down, and described nothing about
     * the subject: a revocation list is created and expires; it never changes.
     *
     * The listener now skips entities without `modified`, and so the column goes away.
     */

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getJti(): ?string
    {
        return $this->jti;
    }

    public function setJti(string $jti): void
    {
        $this->jti = $jti;
    }

    public function getExpiresAt(): ?\DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTime $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

}
