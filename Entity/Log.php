<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 *
 * excludeFromSync: The log is the SOURCE of the sync API. Syncing it as well would mean syncing the bookkeeping about the synchronisation.
 * Moved here with 000-000-0013 from the hard-wired list in Api.php —
 * an exclusion list that is not written in any annotation cannot be seen by a project.
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
     * `$id` HAS BEEN REMOVED HERE (010-003-0002).
     *
     * The declaration repeated the one from `Entity\Base` character for character — the same
     * column, the same strategy, the same CustomIdGenerator. ORM 2 silently overrode the
     * repetition; ORM 3 rejects it:
     *
     *     Duplicate definition of column 'id' on entity 'Areanet\PIM\Entity\Log'
     *     in a field or discriminator column mapping.
     *
     * It is inherited from `Base`, together with the rationale written there.
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
    #[PIM\Select(options: 'UPT=Modified, DEL=Deleted, INS=Created, USERDEL=Deleted for')]
    protected $mode;

    /*
     * `$users`, `$created` AND `$userCreated` HAVE BEEN REMOVED HERE (010-003-0002).
     *
     * All three repeated the declaration from `Entity\Base`. ORM 2 silently overrode the
     * repetition, ORM 3 rejects it ("Duplicate definition of column").
     *
     * ONE OF THE THREE WAS NOT IDENTICAL: `$created` was declared here without the
     * `options: ['default' => 'CURRENT_TIMESTAMP']` that `Base` sets. The deviation was
     * justified nowhere and looks like an incomplete copy; its effect on the generated schema
     * has been verified with this task's database comparison.
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
