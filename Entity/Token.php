<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Entity\User;

#[ORM\Entity]
#[ORM\Table(name: 'pim_token')]
class Token
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     **/
    #[ORM\ManyToOne(targetEntity: 'User')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected $user;

    /**
     * THE HASH OF THE TOKEN, NOT THE TOKEN (013-001-0004).
     *
     * Previously this held 128 hex characters from 64 random bytes in plain text. Any read
     * access to the database — a backup, an SQL injection, a dump in the ticket system — thereby
     * handed over ALL active sessions, usable immediately. Now this holds a SHA-256, and on
     * verification the presented token is hashed and the hash is looked up.
     *
     * A FAST HASH, AND FOR A REASON. A token is not a password: 64 random bytes cannot be
     * guessed, so there is nothing a work factor would protect against — it would only make
     * every authenticated request more expensive. No salt, because otherwise the lookup would
     * not work; without a salt the hash is deterministic and the column stays searchable and
     * unique.
     *
     * THE LENGTH STAYS 128, even though a SHA-256 in hex needs only 64 characters. Shortening the
     * column to 64 would have truncated existing 128-character values during the ALTER — on a
     * UNIQUE column, a failure in the middle of the migration. And having room means a later
     * change of the algorithm needs no schema change.
     */
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    protected $token;

    /**
     * The plain text — NOT mapped and only present in the request in which the token was created.
     *
     * It is delivered to the client exactly once, at login. After that it exists exclusively on
     * the client; not even the operator can look it up any more.
     */
    private $plaintext = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    protected $referrer;

    /**
     * What this row is for (013-003-0001).
     *
     * `null` means: an ordinary JwtAccessToken, as before. `refresh` means: a refresh token that
     * is allowed exactly one thing — fetching a new access JWT.
     *
     * THE COLUMN IS NOT COSMETIC. Up to this point the opaque branch of the `TokenHandler`
     * accepted EVERY row from `pim_token` as a JwtAccessToken. A refresh token, however, is valid
     * longer than an access JWT — that is its purpose — and without this marker it would thus be
     * a long-lived master key for the entire API. That is exactly what the refresh model is meant
     * to prevent.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    protected $purpose;

    /** The value of `$purpose` for a refresh token. */
    public const PURPOSE_REFRESH = 'refresh';

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected $created;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected $modified;

    public function __construct()
    {
        $this->created  = new \DateTime();
        $this->modified = new \DateTime();

        /*
         * `random_bytes()` instead of `openssl_random_pseudo_bytes()`: the latter reports via an
         * output parameter whether the result is cryptographically strong — nobody ever read
         * it. `random_bytes()` either returns strong bytes or throws.
         */
        $this->setToken(bin2hex(random_bytes(64)));
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id): void
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param mixed $user
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * Returns the stored HASH — not the token.
     *
     * The plain text is only available in `getPlaintext()` and only in the request in which it
     * was created. Anyone expecting the token here gets a value nobody can log in with; that is
     * the whole point.
     *
     * @return mixed
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Accepts the PLAIN TEXT and stores its hash.
     *
     * The signature was kept deliberately: `SystemController::addToken()` and the code of
     * existing projects pass a self-chosen token string here, and it is supposed to be hashed
     * without every call site having to remember to do so.
     *
     * @param mixed $token
     */
    public function setToken($token): void
    {
        $this->plaintext = ($token === null) ? null : (string) $token;
        $this->token    = ($token === null) ? null : self::hash((string) $token);
    }

    /**
     * The plain text — or null if this token comes from the database.
     */
    public function getPlaintext(): ?string
    {
        return $this->plaintext;
    }

    /**
     * The algorithm, in one place.
     *
     * It exists as a method and not as a call in five places, so that a later change does not
     * raise the question of whether all of them were caught.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @param \DateTime $created
     */
    public function setCreated($created): void
    {
        $this->created = $created;
    }

    /**
     * @return \DateTime
     */
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * @param \DateTime $modified
     */
    public function setModified($modified): void
    {
        $this->modified = $modified;
    }

    /**
     * @return mixed
     */
    public function getReferrer()
    {
        return $this->referrer;
    }

    /**
     * @param mixed $referrer
     */
    public function setReferrer($referrer): void
    {
        $this->referrer = $referrer;
    }

    /**
     * @return string|null
     */
    public function getPurpose()
    {
        return $this->purpose;
    }

    public function setPurpose(?string $purpose): void
    {
        $this->purpose = $purpose;
    }

    /** Whether this row is a refresh token — and therefore NOT a JwtAccessToken. */
    public function isRefreshToken(): bool
    {
        return $this->purpose === self::PURPOSE_REFRESH;
    }

    



}