<?php
namespace Areanet\PIM\Entity;

use Doctrine\ORM\Mapping as ORM;
use Areanet\PIM\Classes\Annotations as PIM;

#[ORM\MappedSuperclass]
class BaseI18n extends Base
{

    /*
     * DIE SPALTE KOMMT AUS `Base`, HIER STEHT NUR DIE ABWEICHUNG (010-003-0002).
     *
     * Bis ORM 3 stand hier zusaetzlich `#[ORM\Column(type: APPCMS_ID_TYPE)]` — eine wortgleiche
     * Wiederholung der Spalte aus `Entity\Base`. ORM 2 hat sie stillschweigend ueberschrieben,
     * ORM 3 lehnt sie ab („Duplicate definition of column 'id'").
     *
     * Was hier bleiben MUSS, ist die Strategie: `Base` erzeugt eine UUID, eine i18n-Zeile
     * bekommt ihre Id dagegen von der Hauptzeile zugewiesen. Zusammen mit `lang` bildet sie
     * einen zusammengesetzten Schluessel, und ein solcher vertraegt keinen Generator.
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