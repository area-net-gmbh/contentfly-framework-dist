<?php
namespace Areanet\PIM\Classes\Command;

use Areanet\PIM\Classes\Kernel\Command;

/**
 * Die Basisklasse fuer Commands eines Projekts.
 *
 * Sie praefigiert den Namen mit `custom:`, damit Projekt-Commands nie mit denen des
 * Frameworks kollidieren — das ist der Vertrag, den `custom/app.php` beschreibt.
 *
 * Erbt seit 009-001-0006 von `Kernel\Command` statt direkt von `Knp\Command\Command`.
 */
class CustomCommand extends Command
{

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $name = 'custom:'.$name;
        parent::setName($name);

        return $this;
    }
}