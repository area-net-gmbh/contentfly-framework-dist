<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 *
 * excludeFromSync: Das Protokoll ist die QUELLE der Sync-API. Es mitzusynchronisieren hiesse, die Buchfuehrung ueber die Synchronisation zu synchronisieren.
 * Mit 000-000-0013 aus der fest verdrahteten Liste in Api.php hierher geholt —
 * eine Ausschlussliste, die in keiner Annotation steht, kann ein Projekt nicht sehen.
 */
#[ORM\Entity]
#[PIM\Config(excludeFromSync: true)]
#[ORM\Table(name: 'pim_log')]
class Log extends Base
{

    const DELETED   = 'DEL';
    const INSERTED  = 'INS';
    const UPDATED   = 'UPT';
    const USERDEL   = 'USERDEL';

    /*
     * `$id` IST HIER ENTFALLEN (010-003-0002).
     *
     * Die Deklaration wiederholte die aus `Entity\Base` Zeichen fuer Zeichen — dieselbe
     * Spalte, dieselbe Strategie, derselbe CustomIdGenerator. ORM 2 hat die Wiederholung
     * stillschweigend ueberschrieben; ORM 3 lehnt sie ab:
     *
     *     Duplicate definition of column 'id' on entity 'Areanet\PIM\Entity\Log'
     *     in a field or discriminator column mapping.
     *
     * Geerbt wird sie aus `Base`, samt der Begruendung, die dort steht.
     */

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected $isHidden;

    #[ORM\Column(name: 'model_name', type: 'string')]
    #[PIM\Config(isFilterable: true)]
    protected $modelName;

    #[ORM\Column(name: 'model_id', type: APPCMS_ID_TYPE, nullable: false)]
    protected $modelId;

    #[ORM\Column(name: 'model_label', type: 'string', nullable: true)]
    protected $modelLabel;

    #[ORM\Column(type: 'string', length: 100, nullable: false)]
    #[PIM\Config(isFilterable: true)]
    #[PIM\Select(options: 'UPT=Geändert, DEL=Gelöscht, INS=Erstellt, USERDEL=Gelöscht für')]
    protected $mode;

    /*
     * `$users`, `$created` UND `$userCreated` SIND HIER ENTFALLEN (010-003-0002).
     *
     * Alle drei wiederholten die Deklaration aus `Entity\Base`. ORM 2 hat die Wiederholung
     * stillschweigend ueberschrieben, ORM 3 lehnt sie ab („Duplicate definition of column").
     *
     * EINE DER DREI WAR NICHT WORTGLEICH: `$created` stand hier ohne
     * `options: ['default' => 'CURRENT_TIMESTAMP']`, das `Base` setzt. Die Abweichung war
     * nirgends begruendet und sieht nach einer unvollstaendigen Kopie aus; ihre Wirkung auf
     * das erzeugte Schema ist mit dem Datenbankvergleich dieses Tasks nachgemessen.
     */

    #[ORM\Column(type: 'text', nullable: true)]
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
