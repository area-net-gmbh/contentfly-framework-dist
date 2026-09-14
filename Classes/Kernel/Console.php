<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Console\Application as SymfonyConsole;

/**
 * The framework's console (009-002-0005).
 *
 * REPLACES `Knp\Console\Application` from `knplabs/console-service-provider`. That package capped
 * `symfony/console` at `^4` and left the tree with `009-002-0001`. What it provided was thin: a
 * Symfony console that knows the application and dispatches an event once it is ready.
 *
 * Both live here, in forty lines instead of a package.
 */
class Console extends SymfonyConsole
{
    public function __construct(
        private readonly ApplicationInterface $application,
        string $name,
        string $version,
        private readonly string $projectDir
    ) {
        parent::__construct($name, $version);
    }

    /**
     * The application the commands run in.
     *
     * `Kernel\Command::application()` fetches it here. The predecessor method was called
     * `getSilexApplication()` and described the wrong thing after the kernel switch.
     */
    public function application(): ApplicationInterface
    {
        return $this->application;
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Dispatches `ConsoleEvents::INIT` before the first command runs.
     *
     * **The timing is the point.** The `ConsoleManager` does not register commands directly but
     * attaches a listener to this event — projects announce their commands in `custom/app.php`,
     * long before the console exists. It is dispatched on the first `run()`, when everything is in
     * place and nothing has run yet.
     */
    public function run(
        ?\Symfony\Component\Console\Input\InputInterface $input = null,
        ?\Symfony\Component\Console\Output\OutputInterface $output = null
    ): int {
        $this->application['dispatcher']->dispatch(new ConsoleInitEvent($this), ConsoleEvents::INIT);

        return parent::run($input, $output);
    }
}
