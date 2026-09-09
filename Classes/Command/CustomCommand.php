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
     * Setzt den Namen und stellt `custom:` davor.
     *
     * Signatur seit 009-002-0005 `(string $name): static`. Symfony Console 7 deklariert sie
     * so, und eine Implementierung darf sie nicht aufweichen — unter Console 4 war beides
     * untypisiert.
     */
    public function setName(string $name): static
    {
        return parent::setName('custom:'.$name);
    }
}