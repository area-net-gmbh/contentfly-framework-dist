<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\MappedSuperclass]
class BaseI18n extends Base
{

    /*
     * THE COLUMN COMES FROM `Base`, ONLY THE DEVIATION IS STATED HERE (010-003-0002).
     *
     * Until ORM 3 there was additionally `#[ORM\Column(type: APPCMS_ID_TYPE)]` here — a verbatim
     * repetition of the column from `Entity\Base`. ORM 2 silently overwrote it, ORM 3 rejects
     * it ("Duplicate definition of column 'id'").
     *
     * What MUST stay here is the strategy: `Base` generates a UUID, whereas an i18n row gets its
     * id assigned from the main row. Together with `lang` it forms a composite key, and such a
     * key does not tolerate a generator.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    protected $id;

    #[ORM\Column(type: 'string', length: 2, options: ['default' => APP_CMS_MAIN_LANG])]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    protected $lang = APP_CMS_MAIN_LANG;



    /**
     * @return mixed
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @param mixed $lang
     */
    public function setLang($lang): void
    {
        $this->lang = $lang;
    }




}