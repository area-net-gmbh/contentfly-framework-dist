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
     * DER HASH DES TOKENS, NICHT DER TOKEN (013-001-0004).
     *
     * Vorher standen hier 128 Hex aus 64 Zufallsbytes im Klartext. Ein Lesezugriff auf die
     * Datenbank — ein Backup, eine SQL-Injection, ein Dump im Ticketsystem — uebergab damit
     * SAEMTLICHE laufenden Sitzungen, sofort verwendbar. Jetzt steht hier ein SHA-256, und
     * beim Pruefen wird der vorgezeigte Token gehasht und der Hash nachgeschlagen.
     *
     * EIN SCHNELLER HASH, UND ZWAR BEGRUENDET. Ein Token ist kein Passwort: 64 zufaellige
     * Bytes lassen sich nicht raten, es gibt also nichts, wogegen ein Arbeitsfaktor schuetzen
     * wuerde — er verteuerte nur jeden authentifizierten Request. Kein Salt, weil das
     * Nachschlagen sonst nicht ginge; ohne Salt ist der Hash deterministisch und die Spalte
     * bleibt durchsuchbar und unique.
     *
     * DIE LAENGE BLEIBT 128, obwohl ein SHA-256 als Hex nur 64 Zeichen braucht. Die Spalte auf
     * 64 zu kuerzen haette einen Bestand aus 128 Zeichen beim ALTER abgeschnitten — auf einer
     * UNIQUE-Spalte ein Fehlschlag mitten in der Migration. Und Platz zu haben heisst, ein
     * spaeterer Wechsel des Verfahrens braucht keine Schemaaenderung.
     */
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    protected $token;

    /**
     * Der Klartext — NICHT gemappt und nur in dem Request vorhanden, in dem der Token entstand.
     *
     * Er wird dem Client genau einmal ausgeliefert, bei der Anmeldung. Danach existiert er
     * ausschliesslich beim Client; auch der Betreiber kann ihn nicht mehr nachschlagen.
     */
    private $klartext = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    protected $referrer;

    /**
     * Wozu diese Zeile da ist (013-003-0001).
     *
     * `null` heisst: ein gewoehnlicher Zugangstoken, wie bisher. `refresh` heisst: ein
     * Refresh-Token, das genau eine Sache darf — ein neues Access-JWT holen.
     *
     * DIE SPALTE IST NICHT KOSMETIK. Der opaque Zweig des `Tokenhandler` nahm bis hierhin JEDE
     * Zeile aus `pim_token` als Zugangstoken an. Ein Refresh-Token ist aber laenger gueltig als
     * ein Access-JWT — das ist sein Zweck —, und ohne diesen Vermerk waere es damit ein
     * langlebiger Generalschluessel fuer die ganze API. Genau das soll das Refresh-Modell
     * verhindern.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    protected $purpose;

    /** Der Wert von `$purpose` fuer ein Refresh-Token. */
    public const ZWECK_REFRESH = 'refresh';

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
         * `random_bytes()` statt `openssl_random_pseudo_bytes()`: Die zweite meldet ueber einen
         * Ausgabeparameter, ob das Ergebnis kryptographisch stark ist — niemand hat ihn je
         * gelesen. `random_bytes()` liefert entweder starke Bytes oder wirft.
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
     * Liefert den gespeicherten HASH — nicht den Token.
     *
     * Der Klartext steht nur in `getKlartext()` und nur in dem Request, in dem er entstand.
     * Wer hier den Token erwartet, bekommt einen Wert, mit dem sich niemand anmelden kann; das
     * ist der Sinn der Sache.
     *
     * @return mixed
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Nimmt den KLARTEXT entgegen und legt seinen Hash ab.
     *
     * Die Signatur ist absichtlich geblieben: `SystemController::addToken()` und der Code von
     * Bestandsprojekten uebergeben hier einen selbstgewaehlten Token-String, und der soll
     * gehasht werden, ohne dass jede Aufrufstelle daran denken muss.
     *
     * @param mixed $token
     */
    public function setToken($token): void
    {
        $this->klartext = ($token === null) ? null : (string) $token;
        $this->token    = ($token === null) ? null : self::hashen((string) $token);
    }

    /**
     * Der Klartext — oder null, wenn dieser Token aus der Datenbank kommt.
     */
    public function getKlartext(): ?string
    {
        return $this->klartext;
    }

    /**
     * Das Verfahren, an einer Stelle.
     *
     * Es steht als Methode und nicht als Aufruf an fuenf Orten da, damit ein spaeterer Wechsel
     * nicht die Frage aufwirft, ob man alle erwischt hat.
     */
    public static function hashen(string $klartext): string
    {
        return hash('sha256', $klartext);
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

    /** Ob diese Zeile ein Refresh-Token ist — und damit KEIN Zugangstoken. */
    public function istRefreshToken(): bool
    {
        return $this->purpose === self::ZWECK_REFRESH;
    }

    



}