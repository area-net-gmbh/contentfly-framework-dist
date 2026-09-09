<?php
namespace Areanet\PIM\Classes\Kernel;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Die Basisklasse der Console-Commands (009-002-0005).
 *
 * Erbt seit dem Kernel-Schnitt direkt von Symfonys `Command`. Bis dahin war sie die Fuge zu
 * `Knp\Command\Command` — dieselbe Konstruktion, die `Kernel\Application` gegenüber Silex
 * hatte, und aus demselben Grund: `knplabs/console-service-provider` deckelte
 * `symfony/console` auf `^4`. Das Paket ist mit `009-002-0001` aus dem Baum, und mit ihm die
 * Vererbung.
 *
 * **`getSilexApplication()` gibt es nicht mehr.** Sie war geerbt und ist mit dem Paket
 * gefallen; ein Bestandsprojekt, das sie ruft, bekommt einen Fehler. Das steht als Bruchstelle
 * im Migrationsleitfaden — laut ist besser als still. Der Ersatz heisst `anwendung()` und
 * beschreibt, was er liefert.
 */
abstract class Command extends SymfonyCommand
{
    /**
     * Die Anwendung, in der dieser Command läuft.
     *
     * Console-Commands bekommen sie nicht im Konstruktor: Sie werden registriert, bevor die
     * Anwendung steht (siehe `ConsoleManager`), und holen sie sich erst beim Ausführen.
     */
    protected function anwendung(): ApplicationInterface
    {
        $console = $this->getApplication();

        if (!$console instanceof Console) {
            throw new \LogicException(sprintf(
                'Dieser Command laeuft in "%s" statt in Areanet\PIM\Classes\Kernel\Console '
                .'und kommt deshalb nicht an die Anwendung. Registriert wird ueber den '
                .'ConsoleManager oder ueber bin/console.php.',
                $console === null ? 'keiner Console' : get_class($console)
            ));
        }

        return $console->anwendung();
    }
}
