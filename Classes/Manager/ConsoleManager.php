<?php
namespace Areanet\PIM\Classes\Manager;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Areanet\PIM\Classes\Command\CustomCommand;
use Areanet\PIM\Classes\Manager;
use Areanet\PIM\Classes\Kernel\ConsoleEvents;

class ConsoleManager extends Manager
{
    /**
     * @param ConsoleManager $command
     */
    public function addCommand(CustomCommand $command)
    {
        $this->app->extend('dispatcher', function (EventDispatcherInterface $dispatcher, $app) use ($command) {
            /*
             * Ohne Typangabe am Ereignis (009-001-0003).
             *
             * Sie lautete `Knp\Console\ConsoleEvent` — der einzige Grund, warum dieser Manager
             * das Paket ueberhaupt nannte. Das Paket geht mit 009-002 weg, weil es
             * symfony/console auf ^4 deckelt; was hier gebraucht wird, ist einzig
             * `getApplication()`. Die Angabe faellt deshalb weg statt gegen eine eigene
             * Attrappe getauscht zu werden, die dasselbe Ereignis nur anders benennt.
             */
            $dispatcher->addListener(ConsoleEvents::INIT, function ($event) use ($command) {
                $event->console()->add($command);
            });

            return $dispatcher;
        });
    }
}
