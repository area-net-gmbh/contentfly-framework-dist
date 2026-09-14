<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * The base class of console commands (009-002-0005).
 *
 * Since the kernel cut it extends Symfony's `Command` directly. Until then it was the seam to
 * `Knp\Command\Command` — the same construction `Kernel\Application` had towards Silex, and for
 * the same reason: `knplabs/console-service-provider` capped `symfony/console` at `^4`. The
 * package left the tree with `009-002-0001`, and the inheritance went with it.
 *
 * **`getSilexApplication()` no longer exists.** It was inherited and went away with the package;
 * an existing project that calls it gets an error. That is listed as a breaking change in the
 * migration guide — loud is better than silent. Its replacement is called `application()` and
 * describes what it returns.
 */
abstract class Command extends SymfonyCommand
{
    /**
     * The application this command runs in.
     *
     * Console commands do not receive it in their constructor: they are registered before the
     * application is ready (see `ConsoleManager`) and only fetch it when they execute.
     */
    protected function application(): ApplicationInterface
    {
        $console = $this->getApplication();

        if (!$console instanceof Console) {
            throw new \LogicException(sprintf(
                'This command runs in "%s" instead of Areanet\PIM\Classes\Kernel\Console '
                .'and therefore cannot reach the application. Register it through the '
                .'ConsoleManager or through bin/console.php.',
                $console === null ? 'no console' : get_class($console)
            ));
        }

        return $console->application();
    }
}
