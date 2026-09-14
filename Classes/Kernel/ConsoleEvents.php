<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * The console's events (009-001-0003).
 *
 * `INIT` carries the same value as `Knp\Console\ConsoleEvents::INIT` — `'console.init'`. That is
 * no coincidence but the purpose: as long as `knplabs/console-service-provider` starts the
 * console, it listens to this name. Having its own place means `ConsoleManager` no longer has to
 * name the package, and `009-002` can change the value without touching the callers — the package
 * goes away there because it caps `symfony/console` at `^4`.
 */
final class ConsoleEvents
{
    /** Dispatched once the console application is ready and accepts commands. */
    const INIT = 'console.init';
}
