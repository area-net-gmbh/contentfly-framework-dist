<?php
namespace Areanet\PIM\Classes\Kernel;

use Silex\Application as SilexApplication;

/**
 * Die Anwendung des Frameworks — heute mit Silex darunter (009-001-0001).
 *
 * DIESE KLASSE IST DIE FUGE. Sie erbt von `Silex\Application`, was sie kann, und sagt über
 * `ApplicationInterface` zu, was das Framework von ihr benutzt. Alles andere im Baum
 * type-hinted gegen die Schnittstelle, nicht gegen Silex.
 *
 * WAS MIT IHR IN `009-002` PASSIERT: Die Vererbung fällt weg. An ihre Stelle tritt ein
 * Symfony-7.4-Kernel mit DI-Container, und `ArrayAccess` wird zum Bridge auf diesen Container,
 * der die bestehenden String-Schlüssel am Leben hält. Die Schnittstelle bleibt Wort für Wort
 * dieselbe — **kein Aufrufer merkt den Wechsel**. Genau dafür gibt es sie.
 *
 * Der Grund, warum das ein eigener Schritt ist und nicht Teil des Schnitts: `silex/silex`
 * 2.3.0 fordert vier Symfony-Komponenten auf `^4.0`. Alter und neuer Kernel können nicht
 * nebeneinander in einem `vendor/` liegen. Was sich vor dem Schnitt erledigen lässt, muss vor
 * dem Schnitt erledigt sein — sonst passiert alles gleichzeitig, und eine Abweichung der Suite
 * lässt sich hinterher niemandem mehr zuordnen.
 *
 * Der Rumpf ist leer und soll es bleiben. Jede Methode, die hier entsteht, ist eine, die
 * `009-002` zusätzlich nachbauen muss.
 */
class Application extends SilexApplication implements ApplicationInterface
{
}
