<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * Die Ereignisse der Console (009-001-0003).
 *
 * `INIT` trägt denselben Wert wie `Knp\Console\ConsoleEvents::INIT` — `'console.init'`. Das ist
 * kein Zufall, sondern der Zweck: Solange `knplabs/console-service-provider` die Console
 * startet, hört sie auf diesen Namen. Der eigene Ort dafür sorgt dafür, dass `ConsoleManager`
 * das Paket nicht mehr nennen muss, und `009-002` kann den Wert ändern, ohne die Aufrufer
 * anzufassen — dort fällt das Paket weg, weil es `symfony/console` auf `^4` deckelt.
 */
final class ConsoleEvents
{
    /** Wird ausgelöst, wenn die Console-Anwendung steht und Commands entgegennimmt. */
    const INIT = 'console.init';
}
