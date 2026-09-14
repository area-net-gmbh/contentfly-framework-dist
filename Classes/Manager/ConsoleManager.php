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
             * Without a type declaration on the event (009-001-0003).
             *
             * It read `Knp\Console\ConsoleEvent` — the only reason this manager mentioned the
             * package at all. The package goes away with 009-002 because it caps
             * symfony/console at ^4; all that is needed here is `getApplication()`. The
             * declaration is therefore dropped instead of being swapped for a home-made
             * dummy that merely gives the same event a different name.
             */
            $dispatcher->addListener(ConsoleEvents::INIT, function ($event) use ($command) {
                $event->console()->addCommand($command);
            });

            return $dispatcher;
        });
    }
}
