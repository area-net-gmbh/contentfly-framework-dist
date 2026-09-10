<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

#[ORM\Entity]
#[ORM\Table(name: 'pim_user')]
#[PIM\Config(labelProperty: 'alias')]
class User extends Base
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
     * Der Passwort-Hash. 255 Zeichen seit 013-001-0001.
     *
     * Vorher 100. Ein Argon2id-Hash ist rund 96 Zeichen — es haette knapp gepasst und war
     * trotzdem zu eng: PHP darf Vorgabe-Algorithmus und -Parameter zwischen Versionen aendern,
     * und die Laenge ist keine Zusicherung. Ein abgeschnittener Hash faellt nicht beim
     * Speichern auf, sondern erst beim naechsten Login — als „Passwort falsch".
     */
    #[ORM\Column(type: 'string', length: 255)]
    protected $pass;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected $isActive = true;

    #[ORM\Column(type: 'string', length: 100)]
    protected $salt;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    protected $loginManager;

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
     * Setzt das Passwort — mit `password_hash()` (013-001-0001).
     *
     * BIS HIERHER STAND HIER `hash('sha256', $pass.$this->salt)`. Der Salt war in Ordnung —
     * 64 Hex je Benutzer, im Konstruktor erzeugt —, aber SHA-256 hat **keinen Arbeitsfaktor**.
     * Eine GPU prueft Milliarden Kandidaten pro Sekunde; ein Passwort aus einer Wortliste
     * faellt in Sekunden.
     *
     * `password_hash()` bringt seinen Salt selbst mit und traegt Algorithmus und Parameter im
     * Ergebnis. Der eigene `$salt` wird fuer neue Hashes nicht mehr gebraucht — er BLEIBT
     * trotzdem, solange Bestandsdaten mit ihm geprueft werden (siehe `isPass()`).
     *
     * @param mixed $pass
     */
    public function setPass($pass): void
    {
        $this->pass = password_hash((string) $pass, self::verfahren());
    }

    /**
     * Prueft das Passwort — neues Format oder altes.
     *
     * ALTE HASHES BLEIBEN LESBAR, damit kein Bestandsprojekt seine Benutzer aussperrt. Erkannt
     * werden sie am Format: Ein `password_hash()`-Ergebnis beginnt mit `$` (`$argon2id$…`,
     * `$2y$…`), ein SHA-256-Hex nie.
     *
     * Umgeschluesselt wird beim Login, nicht hier — `isPass()` darf nichts schreiben, sonst
     * haette eine Pruefung eine Nebenwirkung. Siehe `AuthController::loginAction()`.
     *
     * `hash_equals()` statt `==` auch fuer den alten Zweig: Der Vergleich ist damit
     * zeitkonstant. Beim neuen Format erledigt `password_verify()` das von sich aus.
     *
     * @return boolean
     */
    public function isPass($pass)
    {
        if ($this->istAltformat()) {
            return hash_equals((string) $this->pass, hash('sha256', $pass.$this->salt));
        }

        return password_verify((string) $pass, (string) $this->pass);
    }

    /**
     * Das Hash-Verfahren fuer neue Passwoerter.
     *
     * Argon2id ist die Empfehlung fuer neue Anwendungen: speicherhart, damit sich der Vorteil
     * spezialisierter Hardware nicht beliebig skalieren laesst. Lokal und in beiden
     * Pipeline-Images vorhanden — nachgemessen mit 013-001-0001.
     *
     * ZUR LAUFZEIT ENTSCHIEDEN, NICHT ALS KONSTANTE: `PASSWORD_ARGON2ID` gibt es nur, wenn PHP
     * mit libargon2 gebaut wurde. Als Klassenkonstante wuerde die Klasse auf einem Build ohne
     * libargon2 gar nicht mehr laden — ein Rueckfall, der die Anwendung umbringt, ist keiner.
     *
     * `PASSWORD_DEFAULT` ist dann der zweitbeste Stand (heute bcrypt) und kein Sicherheitsloch.
     * Weil das Verfahren im Hash steht, laufen beide Formen nebeneinander, und
     * `password_needs_rehash()` holt einen bcrypt-Hash spaeter nach, wenn Argon2id verfuegbar
     * wird.
     *
     * @return string|int
     */
    private static function verfahren()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Liegt der gespeicherte Hash noch im alten SHA-256-Format?
     *
     * Gebraucht vom Login, um nach erfolgreicher Pruefung umzuschluesseln.
     */
    public function istAltformat(): bool
    {
        return !str_starts_with((string) $this->pass, '$');
    }

    /**
     * Muss der Hash erneuert werden — altes Format oder veraltete Parameter?
     *
     * PHP darf Vorgabe-Algorithmus und -Parameter zwischen Versionen aendern; `password_hash()`
     * schreibt sie in den Hash, und `password_needs_rehash()` vergleicht. Damit wandern
     * Bestandsdaten nicht nur einmal mit, sondern bleiben auf dem jeweils aktuellen Stand.
     */
    public function brauchtNeuenHash(): bool
    {
        return $this->istAltformat() || password_needs_rehash((string) $this->pass, self::verfahren());
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
}
