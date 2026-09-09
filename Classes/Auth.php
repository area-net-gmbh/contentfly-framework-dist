<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * Zugriff auf den angemeldeten Benutzer.
 *
 * Bis 2026-09-04 hielt diese Klasse zusätzlich die sessionbasierte Anmeldung der
 * PIM-Oberfläche: `init()` las bei **jedem** Request `auth.userid` aus der PHP-Session,
 * `login()` und `logout()` schrieben sie. Mit der Oberfläche ist das entfallen (Story
 * `012-004`) — und mit ihr die Session selbst, deren exklusiver Dateilock gleichzeitige
 * API-Aufrufe desselben Nutzers serialisierte.
 *
 * `login()` und `logout()` hatten ohnehin keinen Aufrufer: Der `AuthController` bringt seine
 * eigene Anmeldung mit und stellt Tokens aus. Wer den angemeldeten Benutzer setzt, ist
 * seither `BaseControllerProvider::checkToken()`.
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
