<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

/**
 * @ORM\MappedSuperclass
 */
class BaseI18n extends Base
{

    /**
     * @ORM\Column(type=APPCMS_ID_TYPE)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=2, options={"default" = APP_CMS_MAIN_LANG})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
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