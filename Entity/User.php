<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * SINCE 013-002-0001 THE USER IS ALSO A SYMFONY USER.
 *
 * `UserInterface` requires three methods, and nothing more is mapped here either. That is the
 * boundary the story explicitly draws: Contentfly's own permission model —
 * `Permission`, `I18nPermission`, `Group`, `isAdmin` — is NOT replaced by Symfony roles.
 * Only what access control needs in order to identify a user is mapped.
 *
 * Whoever starts translating permissions into roles here builds a second permission model
 * next to the existing one — and two models that are supposed to say the same thing drift
 * apart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_user')]
/*
 * ONE EXTERNAL SYSTEM, ONE IDENTIFIER, ONE ACCOUNT (013-004-0002).
 *
 * Two providers that deliver the same user name result in two accounts — and the same
 * provider with the same identifier always finds the same account again. That is exactly what
 * the MD5 prefix in the alias used to achieve, only unreadably.
 */
#[ORM\UniqueConstraint(name: 'uniq_user_fremdkennung', columns: ['loginManager', 'externalId'])]
#[PIM\Config(labelProperty: 'alias')]
class User extends Base implements UserInterface
{


    use \Custom\Traits\User;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected $isAdmin;

    #[ORM\ManyToOne(targetEntity: 'Areanet\\PIM\\Entity\\Group')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    #[PIM\Config(isFilterable: true)]
    protected $group;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    protected $alias;

    /**
     * The password hash. 255 characters since 013-001-0001.
     *
     * Previously 100. An Argon2id hash is about 96 characters — it would just have fit and was
     * still too tight: PHP may change the default algorithm and parameters between versions,
     * and the length is not a guarantee. A truncated hash does not show up when saving, but
     * only at the next login — as "wrong password".
     */
    #[ORM\Column(type: 'string', length: 255)]
    protected $pass;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected $isActive = true;

    #[ORM\Column(type: 'string', length: 100)]
    protected $salt;

    /**
     * The NAME of the login provider through which this user comes in (013-004-0002).
     *
     * Until then, this held the class name from `get_class($this)`. Since `013-004-0001` no
     * class name selects anything any more; what is stored here is the name from the
     * `LoginProviderRegistry` — `ldap`, `saml`, whatever a project has registered.
     *
     * If it is set, the user can log in ONLY via this route. That was already the case before
     * and remains so — but it is now the second safeguard and no longer the only one: their
     * password is locked.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    protected $loginManager;

    /**
     * The identifier of this user IN THE EXTERNAL SYSTEM (013-004-0002).
     *
     * IT IS STORED IN READABLE FORM, and that is the point. Previously
     * `createManagedUser()` mangled the alias into `md5($class).'-'.$alias`: whoever looked in
     * `pim_user` found `3f2a…-mmustermann` and did not know who that was. The prefix solved a
     * real problem — two external systems that deliver the same user name must not get the same
     * account —, but it solved it by making the answer unreadable.
     *
     * Uniqueness now applies to `loginManager` AND `externalId` together; the alias carries
     * both visibly as `<provider>:<kennung>`.
     */
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    protected $externalId;

    protected $tempData;

    public function __construct()
    {
        parent::__construct();

        $token = bin2hex(openssl_random_pseudo_bytes(32));
        $this->setSalt($token);
        $this->isActive = true;
    }

    /**
     * @return mixed
     */
    public function getIsAdmin()
    {
        return $this->isAdmin;
    }

    /**
     * @param mixed $isAdmin
     */
    public function setIsAdmin($isAdmin): void
    {
        $this->isAdmin = $isAdmin;
    }





    /**
     * @return mixed
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @param mixed $alias
     */
    public function setAlias($alias): void
    {
        $this->alias = $alias;
    }

    /**
     * @return mixed
     */
    public function getPass()
    {
        return $this->pass;
    }

    /**
     * Sets the password — with `password_hash()` (013-001-0001).
     *
     * UNTIL NOW THIS SAID `hash('sha256', $pass.$this->salt)`. The salt was fine —
     * 64 hex per user, generated in the constructor —, but SHA-256 has **no work factor**.
     * A GPU checks billions of candidates per second; a password from a word list falls
     * within seconds.
     *
     * `password_hash()` brings its own salt and carries algorithm and parameters in the
     * result. The separate `$salt` is no longer needed for new hashes — it STAYS
     * nevertheless, as long as existing data is verified with it (see `isPass()`).
     *
     * @param mixed $pass
     */
    public function setPass($pass): void
    {
        $this->pass = password_hash((string) $pass, self::algorithm());
    }

    /**
     * Checks the password — new format or old.
     *
     * OLD HASHES REMAIN READABLE, so that no existing project locks out its users. They are
     * recognised by their format: a `password_hash()` result starts with `$` (`$argon2id$…`,
     * `$2y$…`), a SHA-256 hex never does.
     *
     * Rehashing happens at login, not here — `isPass()` must not write anything, otherwise
     * a check would have a side effect. See `AuthController::loginAction()`.
     *
     * `hash_equals()` instead of `==` for the old branch as well: this makes the comparison
     * constant-time. For the new format, `password_verify()` takes care of that on its own.
     *
     * @return boolean
     */
    public function isPass($pass)
    {
        /*
         * A LOCKED PASSWORD MATCHES NOTHING (013-004-0002).
         *
         * Checked explicitly and not left to chance: `PASSWORD_LOCKED` is not a valid hash,
         * which is why the two branches below would already reject any input. Relying on that
         * would mean deriving a security guarantee from a side effect — and the next change
         * to the hash format takes it away without anyone noticing.
         */
        if ($this->isPasswordLocked()) {
            return false;
        }

        if ($this->isLegacyFormat()) {
            return hash_equals((string) $this->pass, hash('sha256', $pass.$this->salt));
        }

        return password_verify((string) $pass, (string) $this->pass);
    }

    /**
     * The hashing algorithm for new passwords.
     *
     * Argon2id is the recommendation for new applications: memory-hard, so that the advantage
     * of specialised hardware cannot be scaled arbitrarily. Available locally and in both
     * pipeline images — verified with 013-001-0001.
     *
     * DECIDED AT RUNTIME, NOT AS A CONSTANT: `PASSWORD_ARGON2ID` only exists if PHP was built
     * with libargon2. As a class constant, the class would not even load on a build without
     * libargon2 — a fallback that kills the application is no fallback.
     *
     * `PASSWORD_DEFAULT` is then the second-best option (bcrypt today) and not a security hole.
     * Because the algorithm is stored in the hash, both forms run side by side, and
     * `password_needs_rehash()` upgrades a bcrypt hash later once Argon2id becomes
     * available.
     *
     * @return string|int
     */
    private static function algorithm()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Is the stored hash still in the old SHA-256 format?
     *
     * Used by the login to rehash after a successful check.
     */
    public function isLegacyFormat(): bool
    {
        return !str_starts_with((string) $this->pass, '$');
    }

    /**
     * The value that locks a password.
     *
     * An asterisk, as in `/etc/shadow` since time immemorial: not a valid hash, not matchable
     * to any input, and the row shows that it was intentional. A random value would do the
     * same, but nobody could tell it apart from a real hash.
     */
    public const PASSWORD_LOCKED = '*';

    /**
     * Locks this user's password (013-004-0002).
     *
     * FINDING A-6 IS WHAT THIS IS ABOUT: `createManagedUser()` called
     * `setPass($alias)` — the password was the user name. The only thing defusing that was the
     * bolt "only authorisable via LoginManager"; every path that bypassed it was a trivial
     * account takeover. A safeguard that consists of a single `if` is no safeguard.
     *
     * A user created via an external system now has NO password — not a random one, but none
     * at all.
     */
    public function lockPassword(): void
    {
        $this->pass = self::PASSWORD_LOCKED;
    }

    public function isPasswordLocked(): bool
    {
        return $this->pass === self::PASSWORD_LOCKED;
    }

    /**
     * @return string|null
     */
    public function getExternalId()
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
    }

    /**
     * Does the hash need to be renewed — old format or outdated parameters?
     *
     * PHP may change the default algorithm and parameters between versions; `password_hash()`
     * writes them into the hash, and `password_needs_rehash()` compares them. This way existing
     * data does not just migrate once, but stays at the current state at all times.
     */
    public function needsRehash(): bool
    {
        // A locked password is not rehashed — after all, it is not supposed to become one.
        if ($this->isPasswordLocked()) {
            return false;
        }

        return $this->isLegacyFormat() || password_needs_rehash((string) $this->pass, self::algorithm());
    }

    /**
     * @return mixed
     */
    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * @param mixed $salt
     */
    public function setSalt($salt): void
    {
        $this->salt = $salt;
    }

    /**
     * @return mixed
     */
    public function getIsActive()
    {
        return $this->isActive;
    }

    /**
     * @param mixed $isActive
     */
    public function setIsActive($isActive): void
    {
        $this->isActive = $isActive;
    }

    /**
     * @return mixed
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @param mixed $group
     */
    public function setGroup($group): void
    {
        $this->group = $group;
    }

    /**
     * @return mixed
     */
    public function getLoginManager()
    {
        return $this->loginManager;
    }

    /**
     * @param mixed $loginManager
     */
    public function setLoginManager($loginManager): void
    {
        $this->loginManager = $loginManager;
    }

    /**
     * @return mixed
     */
    public function getTempData()
    {
        return $this->tempData;
    }

    /**
     * @param mixed $tempData
     */
    public function setTempData($tempData): void
    {
        $this->tempData = $tempData;
    }


    public function toValueObject(?Application $app = null, $entityName = null, $flatten = false, $propertiesToLoad = array(), $level = 0, $forceLoadAll = false)
    {

        $data = parent::toValueObject($app, $entityName, $flatten, $propertiesToLoad , $level);

        unset($data->salt);
        unset($data->pass);
        unset($data->user);
        unset($data->created);
        unset($data->modified);
        unset($data->userCreated);

        foreach($data as $key => $value){
            if($value === null){
                unset($data->$key);
            }
        }

        return $data;
    }

    // ── Symfony user (013-002-0001) ────────────────────────────────────────────────────

    /**
     * The identifier under which this user is reloaded.
     *
     * The `alias` and not the id: it is unique, it appears in every token context, and it is
     * what a human knows as the user name.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->alias;
    }

    /**
     * Only what access control needs — see the class comment.
     *
     * `ROLE_USER` for every logged-in user, `ROLE_ADMIN` additionally for an administrator.
     * The fine-grained permissions stay where they are: in `Permission` and
     * `I18nPermission`, read by Contentfly's own model.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = array('ROLE_USER');

        if ($this->isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    /**
     * Intentionally empty.
     *
     * The method is meant to clear volatile credentials from the object — a plain-text password,
     * for instance, that is attached to it during login. None is attached here: `$pass` is the
     * stored Argon2id hash (013-001-0001), not a volatile value, and it is serialised nowhere.
     *
     * Symfony marked the method as deprecated with 7.3; it is still part of the interface,
     * however, and therefore has to be declared. Nobody in this tree calls it — the CI's
     * deprecation gate would report it.
     */
    public function eraseCredentials(): void
    {
    }
}
