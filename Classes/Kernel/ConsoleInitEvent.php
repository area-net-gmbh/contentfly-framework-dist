<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Das Ereignis, mit dem die Console ihre Commands einsammelt (009-002-0005).
 *
 * Ersetzt `Knp\Console\ConsoleEvent`. Die Vorgängerklasse hiess `getApplication()` für das,
 * was hier `console()` heisst — die Console selbst, nicht die Anwendung des Frameworks. Der
 * alte Name war doppeldeutig, weil `Application` in diesem Baum beides bedeuten kann.
 */
class ConsoleInitEvent extends Event
{
    public function __construct(private readonly Console $console)
    {
    }

    public function console(): Console
    {
        return $this->console;
    }
}
