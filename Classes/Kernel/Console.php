<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Console\Application as SymfonyConsole;

/**
 * Die Console des Frameworks (009-002-0005).
 *
 * ERSETZT `Knp\Console\Application` aus `knplabs/console-service-provider`. Das Paket deckelte
 * `symfony/console` auf `^4` und ist mit `009-002-0001` aus dem Baum. Was es lieferte, war
 * dünn: eine Symfony-Console, die die Anwendung kennt und ein Ereignis auslöst, sobald sie
 * steht.
 *
 * Beides steht hier, in vierzig Zeilen statt in einem Paket.
 */
class Console extends SymfonyConsole
{
    public function __construct(
        private readonly ApplicationInterface $anwendung,
        string $name,
        string $version,
        private readonly string $projektverzeichnis
    ) {
        parent::__construct($name, $version);
    }

    /**
     * Die Anwendung, in der die Commands laufen.
     *
     * `Kernel\Command::anwendung()` holt sie hier ab. Der Name der Vorgängermethode lautete
     * `getSilexApplication()` und beschrieb nach dem Kernel-Wechsel das Falsche.
     */
    public function anwendung(): ApplicationInterface
    {
        return $this->anwendung;
    }

    public function projektverzeichnis(): string
    {
        return $this->projektverzeichnis;
    }

    /**
     * Löst `ConsoleEvents::INIT` aus, bevor der erste Command läuft.
     *
     * **Der Zeitpunkt ist der Punkt.** Der `ConsoleManager` registriert Commands nicht direkt,
     * sondern hängt einen Listener auf dieses Ereignis — Projekte melden ihre Commands in
     * `custom/app.php` an, also lange bevor die Console existiert. Ausgelöst wird es beim
     * ersten `run()`, wenn alles steht und noch nichts gelaufen ist.
     */
    public function run(
        ?\Symfony\Component\Console\Input\InputInterface $input = null,
        ?\Symfony\Component\Console\Output\OutputInterface $output = null
    ): int {
        $this->anwendung['dispatcher']->dispatch(new ConsoleInitEvent($this), ConsoleEvents::INIT);

        return parent::run($input, $output);
    }
}
