<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The event the console uses to collect its commands (009-002-0005).
 *
 * Replaces `Knp\Console\ConsoleEvent`. The predecessor class used `getApplication()` for what is
 * called `console()` here — the console itself, not the framework's application. The old name was
 * ambiguous because `Application` can mean either in this tree.
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
