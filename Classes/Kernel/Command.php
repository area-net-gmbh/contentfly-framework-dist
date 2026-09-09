<?php
namespace Areanet\PIM\Classes\Kernel;

use Knp\Command\Command as KnpCommand;

/**
 * Die Basisklasse der Console-Commands — heute mit knplabs darunter (009-001-0006).
 *
 * Dieselbe Fuge wie `Kernel\Application` bei Silex, aus demselben Grund:
 * `knplabs/console-service-provider` deckelt `symfony/console` auf `^4` und ist unter
 * Symfony 7.4 nicht mitzunehmen. Statt fünf Dateien, die das Paket nennen, nennt es eine.
 *
 * WAS `009-002` DAMIT TUT: Die Vererbung wechselt auf
 * `Symfony\Component\Console\Command\Command`, und `anwendung()` holt sich die Anwendung aus dem
 * neuen Kernel statt aus `Knp\Console\Application`. Die Commands selbst bleiben unberührt.
 *
 * WARUM `anwendung()` UND NICHT `getSilexApplication()`: Der alte Name beschreibt beim neuen
 * Kernel das Falsche, und ein Name, der lügt, ist schlechter als einer, den man einmal ändern
 * muss. Die alte Methode bleibt geerbt und funktionsfähig — ein Projekt, das sie ruft, bricht
 * nicht —, aber der eigene Code benutzt sie nicht mehr.
 */
abstract class Command extends KnpCommand
{
    /**
     * Die Anwendung, in der dieser Command läuft.
     *
     * Console-Commands bekommen sie nicht im Konstruktor: Sie werden registriert, bevor die
     * Anwendung steht (siehe `ConsoleManager`), und holen sie sich erst beim Ausführen.
     */
    protected function anwendung(): ApplicationInterface
    {
        return $this->getSilexApplication();
    }
}
