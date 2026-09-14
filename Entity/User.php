<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * DER BENUTZER IST SEIT 013-002-0001 AUCH EIN SYMFONY-BENUTZER.
 *
 * `UserInterface` verlangt drei Methoden, und mehr wird hier auch nicht abgebildet. Das ist die
 * Grenze, die die Story ausdruecklich zieht: Das Contentfly-eigene Berechtigungsmodell —
 * `Permission`, `I18nPermission`, `Group`, `isAdmin` — wird NICHT durch Symfony-Rollen ersetzt.
 * Abgebildet wird nur, was der Zugriffsschutz braucht, um einen Benutzer zu identifizieren.
 *
 * Wer hier anfaengt, Berechtigungen in Rollen zu uebersetzen, baut ein zweites
 * Berechtigungsmodell neben dem vorhandenen — und zwei Modelle, die dasselbe sagen sollen,
 * laufen auseinander.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pim_user')]
/*
 * EIN FREMDSYSTEM, EINE KENNUNG, EIN KONTO (013-004-0002).
 *
 * Zwei Provider, die denselben Benutzernamen liefern, ergeben zwei Konten — und derselbe
 * Provider mit derselben Kennung findet immer dasselbe wieder. Genau das leistete frueher der
 * MD5-Praefix im Alias, nur unleserlich.
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

    /**
     * Der NAME des Anmeldeproviders, ueber den dieser Benutzer kommt (013-004-0002).
     *
     * Bis dahin stand hier der Klassenname aus `get_class($this)`. Seit `013-004-0001` waehlt
     * kein Klassenname mehr etwas aus; was hier steht, ist der Name aus dem
     * `LoginProviderRegistry` — `ldap`, `saml`, was ein Projekt eingetragen hat.
     *
     * Ist er gesetzt, ist der Benutzer NUR ueber diesen Weg anmeldbar. Das war schon vorher so
     * und bleibt — es ist jetzt aber die zweite Sicherung und nicht mehr die einzige: Sein
     * Passwort ist gesperrt.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    protected $loginManager;

    /**
     * Die Kennung dieses Benutzers IM FREMDSYSTEM (013-004-0002).
     *
     * SIE STEHT LESBAR DA, und das ist der Punkt. Vorher verfremdete
     * `createManagedUser()` den Alias zu `md5($klasse).'-'.$alias`: Wer in `pim_user` nachsah,
     * fand `3f2a…-mmustermann` und wusste nicht, wer das ist. Der Praefix loeste ein echtes
     * Problem — zwei Fremdsysteme, die denselben Benutzernamen liefern, duerfen nicht dasselbe
     * Konto bekommen —, aber er loeste es, indem er die Antwort unleserlich machte.
     *
     * Die Eindeutigkeit gilt jetzt ueber `loginManager` UND `externalId` zusammen; der Alias
     * traegt beide sichtbar als `<provider>:<kennung>`.
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
        /*
         * EIN GESPERRTES PASSWORT PASST AUF NICHTS (013-004-0002).
         *
         * Ausdruecklich geprueft und nicht dem Zufall ueberlassen: `PASSWORT_GESPERRT` ist kein
         * gueltiger Hash, weshalb schon die beiden Zweige darunter jede Eingabe abweisen
         * wuerden. Sich darauf zu verlassen hiesse, eine Sicherheitszusicherung aus einer
         * Nebenwirkung zu beziehen — und die naechste Aenderung an der Hashform nimmt sie
         * mit, ohne dass jemand es bemerkt.
         */
        if ($this->istPasswortGesperrt()) {
            return false;
        }

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
     * Der Wert, der ein Passwort sperrt.
     *
     * Ein Stern, wie in `/etc/shadow` seit jeher: kein gueltiger Hash, keiner Eingabe
     * zuzuordnen, und man sieht der Zeile an, dass es Absicht war. Ein Zufallswert taete
     * dasselbe, aber niemand koennte ihn von einem echten Hash unterscheiden.
     */
    public const PASSWORT_GESPERRT = '*';

    /**
     * Sperrt das Passwort dieses Benutzers (013-004-0002).
     *
     * BEFUND A-6 IST DAS, WOGEGEN ES GEHT: `createManagedUser()` setzte
     * `setPass($alias)` — das Passwort war der Benutzername. Entschaerft war das allein durch
     * den Riegel „nur ueber LoginManager authorisierbar"; jeder Pfad, der ihn umging, war eine
     * triviale Kontouebernahme. Eine Sicherung, die aus einem einzigen `if` besteht, ist keine.
     *
     * Ein ueber ein Fremdsystem angelegter Benutzer hat jetzt KEIN Passwort — nicht ein
     * zufaelliges, sondern gar keines.
     */
    public function passwortSperren(): void
    {
        $this->pass = self::PASSWORT_GESPERRT;
    }

    public function istPasswortGesperrt(): bool
    {
        return $this->pass === self::PASSWORT_GESPERRT;
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
     * Muss der Hash erneuert werden — altes Format oder veraltete Parameter?
     *
     * PHP darf Vorgabe-Algorithmus und -Parameter zwischen Versionen aendern; `password_hash()`
     * schreibt sie in den Hash, und `password_needs_rehash()` vergleicht. Damit wandern
     * Bestandsdaten nicht nur einmal mit, sondern bleiben auf dem jeweils aktuellen Stand.
     */
    public function brauchtNeuenHash(): bool
    {
        // Ein gesperrtes Passwort wird nicht umgeschluesselt — es soll ja keines werden.
        if ($this->istPasswortGesperrt()) {
            return false;
        }

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

    // ── Symfony-Benutzer (013-002-0001) ────────────────────────────────────────────────

    /**
     * Die Kennung, unter der dieser Benutzer nachgeladen wird.
     *
     * Der `alias` und nicht die Id: Er ist unique, er steht in jedem Token-Zusammenhang, und er
     * ist das, was ein Mensch als Benutzernamen kennt.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->alias;
    }

    /**
     * Nur was der Zugriffsschutz braucht — siehe Klassenkommentar.
     *
     * `ROLE_USER` fuer jeden angemeldeten Benutzer, `ROLE_ADMIN` zusaetzlich fuer einen
     * Administrator. Die feinen Rechte bleiben, wo sie sind: in `Permission` und
     * `I18nPermission`, gelesen vom Contentfly-eigenen Modell.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $rollen = array('ROLE_USER');

        if ($this->isAdmin) {
            $rollen[] = 'ROLE_ADMIN';
        }

        return $rollen;
    }

    /**
     * Absichtlich leer.
     *
     * Die Methode soll fluechtige Zugangsdaten vom Objekt raeumen — ein Klartextpasswort etwa,
     * das waehrend der Anmeldung daran haengt. Hier haengt keines: `$pass` ist der gespeicherte
     * Argon2id-Hash (013-001-0001), kein fluechtiger Wert, und er wird nirgends serialisiert.
     *
     * Symfony hat die Methode mit 7.3 als deprecated markiert; sie steht aber weiter im
     * Interface und muss deshalb deklariert werden. Gerufen wird sie in diesem Baum von
     * niemandem — das Deprecation-Gate der CI wuerde es melden.
     */
    public function eraseCredentials(): void
    {
    }
}
