<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ein widerrufenes Access-JWT, bis es ohnehin abgelaufen waere (013-003-0003).
 *
 * DAS RESTFENSTER, MEHR NICHT. Der Hauptteil des Widerrufs sitzt im Refresh-Modell: Logout
 * loescht die Refresh-Zeile, und damit bekommt niemand mehr ein neues Access-JWT. Was bleibt,
 * ist das eine Token, das der Client gerade in der Hand haelt — es gilt bis zu seinem `exp`,
 * und genau dafuer gibt es diese Tabelle.
 *
 * SIE BLEIBT KLEIN, weil ein Eintrag mit dem Token verfaellt. Das Kundenprojekt hatte eine
 * solche Liste gebaut, aber OHNE das Refresh-Modell daneben — dort musste sie ueber die volle
 * Tokenlaufzeit tragen und wuchs unbegrenzt.
 *
 * WOFUER SIE NICHT GEBRAUCHT WIRD, ist nachgemessen: Eine Benutzersperrung wirkt seit
 * `013-002-0001` sofort. Der JWT-Zweig gibt sein `UserBadge` ohne eigenen Lader zurueck, also
 * laedt der `UserLoader` den Benutzer aus `pim_user` und weist einen gesperrten mit derselben
 * Ausnahme ab wie einen unbekannten. Wer diese Liste fuer die Sperrung baute, baute etwas, das
 * schon steht.
 *
 * SIE LIEGT IN DER DATENBANK UND NICHT IM CACHE. Ein geleerter Cache darf keinen Widerruf
 * aufheben — und der Cache ist genau das, was man leert, wenn etwas klemmt.
 *
 * Sie erbt NICHT von `Base`: Die dortigen Felder (`userCreated`, `isIntern`, GUID-Schluessel)
 * beschreiben Inhaltsobjekte der API. Diese Tabelle ist Infrastruktur, wie `pim_token`, und
 * folgt derselben schlanken Form.
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
     * Die `jti` des widerrufenen Tokens.
     *
     * Im Klartext, und das ist hier richtig: Anders als ein Token ist eine `jti` kein Geheimnis
     * — sie oeffnet nichts. Wer sie liest, erfaehrt, dass irgendein Token widerrufen wurde, und
     * sonst nichts. Ein Hash brauchte einen Grund, und es gibt keinen.
     */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    protected $jti;

    /**
     * Wann das widerrufene Token ohnehin abgelaufen waere.
     *
     * Ab da ist der Eintrag gegenstandslos; `appcms:token:cleanup` raeumt ihn weg.
     *
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected $expiresAt;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    protected $created;

    /*
     * HIER STAND EIN `modified`, DAS NICHTS TAT (bis 000-000-0028).
     *
     * `Classes/Events/LoadMetadata` haengte an JEDE Entity einen Index auf diese Spalte, und
     * ohne sie scheiterte die Installation — mit einer Meldung, die den Grund nicht nannte.
     * Diese Zeile erfuellte also eine Anforderung, die niemand aufgeschrieben hatte, und
     * beschrieb nichts an der Sache: Eine Sperrliste wird angelegt und verfaellt; sie aendert
     * sich nie.
     *
     * Der Listener ueberspringt jetzt Entities ohne `modified`, und damit faellt die Spalte.
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
