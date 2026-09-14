<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * Access to the logged-in user.
 *
 * Until 2026-09-04 this class additionally held the session-based login of the PIM UI:
 * `init()` read `auth.userid` from the PHP session on **every** request, and `login()` and
 * `logout()` wrote it. This was dropped together with the UI (story `012-004`) — and with it
 * the session itself, whose exclusive file lock serialised concurrent API calls by the same
 * user.
 *
 * `login()` and `logout()` had no caller anyway: the `AuthController` brings its own login and
 * issues tokens. Since `013-002-0004`, the one setting the logged-in user is
 * `BaseControllerProvider::anmelden()` — before that it was `checkToken()`, which was dropped
 * with that task.
 */
class Auth
{
    /** @var Application $app */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return User|null
     */
    public function getUser()
    {
        return isset($this->app['auth.user']) ? $this->app['auth.user'] : null;
    }

    /**
     * @param User|null $user
     */
    public function setUser($user): void
    {
        $this->app['auth.user'] = $user;
    }
}
