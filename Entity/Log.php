<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 * @ORM\Entity
 * @PIM\Config(excludeFromSync=true)
 *
 * excludeFromSync: Das Protokoll ist die QUELLE der Sync-API. Es mitzusynchronisieren hiesse, die Buchfuehrung ueber die Synchronisation zu synchronisieren.
 * Mit 000-000-0013 aus der fest verdrahteten Liste in Api.php hierher geholt —
 * eine Ausschlussliste, die in keiner Annotation steht, kann ein Projekt nicht sehen.
 * @ORM\Table(name="pim_log")
 */
class Log extends Base
{

    const DELETED   = 'DEL';
    const INSERTED  = 'INS';
    const UPDATED   = 'UPT';
    const USERDEL   = 'USERDEL';

    /**
     * Die Id.
     *
     * `CUSTOM` statt `UUID` seit 009-005-0002: Doctrines `ORM\Id\UuidGenerator` liess die
     * Datenbank die GUID erzeugen (`SELECT UUID()`) und stuetzte sich dafuer auf
     * `AbstractPlatform::getGuidExpression()` — die Methode gibt es in DBAL 3 nicht mehr.
     * Erzeugt wird sie jetzt in PHP, siehe Areanet\PIM\Classes\ORM\Id\UuidGenerator.
     *
     * `@ORM\CustomIdGenerator` steht auch dann hier, wenn die Integer-Strategie laeuft
     * (APPCMS_ID_STRATEGY = 'AUTO'). Doctrine liest die Angabe nur bei `CUSTOM` aus; sie
     * bedingt zu setzen ginge in einer Annotation nicht, ohne die Konstante zu verdoppeln.
     *
     * @ORM\Column(type=APPCMS_ID_TYPE)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy=APPCMS_ID_STRATEGY)
     * @ORM\CustomIdGenerator(class="Areanet\PIM\Classes\ORM\Id\UuidGenerator")
     */
    protected $id;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $isHidden;

    /**
     * @ORM\Column(name="model_name", type="string")
     * @PIM\Config(isFilterable=true)
     */
    protected $modelName;

    /**
     * @ORM\Column(name="model_id", type=APPCMS_ID_TYPE, nullable=false)
     */
    protected $modelId;

    /**
     * @ORM\Column(name="model_label", type="string", nullable=true)
     */
    protected $modelLabel;

    /**
     * @ORM\Column(type="string", length=100, nullable=false)
     * @PIM\Config(isFilterable=true)
     * @PIM\Select(options="UPT=Geändert, DEL=Gelöscht, INS=Erstellt, USERDEL=Gelöscht für")
     */
    protected $mode;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @PIM\Virtualjoin(targetEntity="Areanet\PIM\Entity\User")
     */
    protected $users;

    /**
     * @var \DateTime
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $created;

    /**
     * @ORM\ManyToOne(targetEntity="Areanet\PIM\Entity\User")
     * @ORM\JoinColumn(name="usercreated_id", referencedColumnName="id", onDelete="SET NULL")
     */
    protected $userCreated;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $data;



    public function getModelName()
    {
        return $this->modelName;
    }

    public function setModelName($modelName): void
    {
        $this->modelName = $modelName;
    }

    public function getModelId()
    {
        return $this->modelId;
    }

    public function setModelId($modelId): void
    {
        $this->modelId = $modelId;
    }


    public function getMode()
    {
        return $this->mode;
    }

    public function setMode($mode): void
    {
        $this->mode = $mode;
    }

    /**
     * @return mixed
     */
    public function getIsHidden()
    {
        return $this->isHidden;
    }

    /**
     * @param mixed $isHidden
     */
    public function setIsHidden($isHidden): void
    {
        $this->isHidden = $isHidden;
    }

    /**
     * @return mixed
     */
    public function getModelLabel()
    {
        return $this->modelLabel;
    }

    /**
     * @param mixed $modelLabel
     */
    public function setModelLabel($modelLabel): void
    {
        $this->modelLabel = $modelLabel;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function setData($data): void
    {
        $this->data = $data;
    }

    
}
