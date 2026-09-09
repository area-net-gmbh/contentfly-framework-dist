<?php
namespace Areanet\PIM\Classes\Kernel;

/**
 * Der Container des Frameworks — String-Schlüssel und faule Factories (009-002-0002).
 *
 * ER BILDET PIMPLES VERTRAG NACH, UND ZWAR ABSICHTLICH. `custom/app.php` registriert die
 * Dienste eines Projekts so:
 *
 *     $app['meine.service'] = function ($app) { return new MeinService($app['orm.em']); };
 *
 * Das ist eine **faule Factory**: Sie läuft beim ersten Zugriff, nicht beim Registrieren, und
 * bekommt den Container als Argument, um ihre Abhängigkeiten aufzulösen. Symfonys
 * DI-Container nimmt zur Laufzeit nur fertige Objekte entgegen; eine Brücke davor müsste die
 * Closures ohnehin selbst halten und wäre am Ende dieser Container. Entschieden am 2026-09-09.
 *
 * WAS ER NICHT IST: eine vollständige Pimple-Nachbildung. `share()`, `protect()`, `raw()`,
 * `factory()` und `register()` fehlen — sie kommen im Baum nicht vor. Was fehlt, kommt dazu,
 * wenn ein Aufrufer es braucht, nicht auf Verdacht.
 *
 * DAS EINFRIEREN IST NACHGEBAUT, WEIL EINE ZUSICHERUNG DARAN HÄNGT. Sobald eine Definition
 * einmal ausgelesen wurde, gilt sie als eingefroren, und `extend()` wirft. Der `ConsoleManager`
 * hängt genau an dieser Reihenfolge: Er ergänzt den Dispatcher über `extend()` und muss das
 * tun, bevor jemand ihn ausliest. `tests/Unit/Manager/RouteAndConsoleManagerTest.php` prüft es
 * seit `008-004`, und `000-000-0006` ist einmal darüber gestolpert. Ein Container, der das
 * stillschweigend erlaubt, würde den Fehler verstecken statt ihn zu melden.
 */
class Container implements \ArrayAccess
{
    /** @var array<string,mixed> Werte und noch nicht aufgelöste Factories. */
    private array $eintraege = array();

    /** @var array<string,true> Schlüssel, deren Definition ausgelesen und damit eingefroren ist. */
    private array $eingefroren = array();

    /** @var array<string,true> Schlüssel, deren Wert eine Factory ist. */
    private array $factories = array();

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->eintraege);
    }

    /**
     * Liest einen Eintrag und löst eine Factory beim ersten Mal auf.
     *
     * Das Ergebnis wird gemerkt: Eine Factory läuft genau einmal, und jeder weitere Zugriff
     * bekommt dasselbe Objekt. Genau darauf beruht, dass `$app['orm.em']` überall derselbe
     * EntityManager ist.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->eintraege)) {
            throw new \InvalidArgumentException(sprintf('Der Container kennt "%s" nicht.', $offset));
        }

        $this->eingefroren[$offset] = true;

        if (!isset($this->factories[$offset])) {
            return $this->eintraege[$offset];
        }

        $factory = $this->eintraege[$offset];
        unset($this->factories[$offset]);

        return $this->eintraege[$offset] = $factory($this);
    }

    /**
     * Setzt einen Wert oder eine Factory.
     *
     * Eine Closure gilt als Factory. Das ist Pimples Regel, und sie hat eine Kehrseite: Wer
     * eine Closure als *Wert* ablegen will — einen Callback, den jemand später aufruft —
     * bekommt sie stattdessen aufgelöst. Pimple hat dafür `protect()`; hier fehlt es, weil im
     * Baum niemand eine Closure als Wert ablegt. Fällt das jemandem auf die Füsse, ist der
     * Fehler laut: Der Aufrufer bekommt den Rückgabewert statt der Closure.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->eintraege[$offset] = $value;
        unset($this->eingefroren[$offset]);

        if ($value instanceof \Closure) {
            $this->factories[$offset] = true;
        } else {
            unset($this->factories[$offset]);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->eintraege[$offset], $this->eingefroren[$offset], $this->factories[$offset]);
    }

    /**
     * Ersetzt eine registrierte Factory durch eine, die die alte umschliesst.
     *
     * Die neue Closure bekommt das Ergebnis der alten und den Container. So hängt der
     * `ConsoleManager` seine Listener an den Dispatcher, ohne ihn selbst zu bauen.
     *
     * OHNE TYPANGABEN, UND DAS IST EINE ENTSCHEIDUNG. `ApplicationInterface::extend($id,
     * $callable)` deklariert sie nicht — die Schnittstelle hat Pimples Signatur übernommen, als
     * Pimple noch darunter lag. Eine typisierte Implementierung erfüllt sie damit nicht: PHP
     * erlaubt einer Implementierung nicht, Parametertypen hinzuzufügen.
     *
     * Die Schnittstelle nachzuziehen wäre technisch kein Bruch — jeder Aufrufer übergibt
     * ohnehin `(string, callable)`. Sie bleibt trotzdem unverändert: `009-002-0002` hat als
     * Kriterium, dass sie Wort für Wort steht, und dieses Kriterium ist eine Stolperdraht gegen
     * das stille Umformen des Vertrags beim Kernel-Wechsel. Der Gewinn aus zwei Typangaben
     * wiegt das nicht auf. Die Angaben stehen deshalb im `@param`.
     *
     * @param string   $id
     * @param callable $callable
     *
     * @throws \RuntimeException wenn der Schlüssel bereits ausgelesen wurde
     */
    public function extend($id, $callable)
    {
        if (!array_key_exists($id, $this->eintraege)) {
            throw new \InvalidArgumentException(sprintf('Der Container kennt "%s" nicht.', $id));
        }

        if (isset($this->eingefroren[$id])) {
            throw new \RuntimeException(sprintf(
                'Der Dienst "%s" ist bereits ausgelesen und laesst sich nicht mehr erweitern. '
                .'Wer extend() benutzt, muss es tun, bevor jemand den Dienst anfasst.',
                $id
            ));
        }

        if (!isset($this->factories[$id])) {
            throw new \InvalidArgumentException(sprintf(
                'Der Eintrag "%s" ist ein Wert, keine Factory — es gibt nichts zu erweitern.',
                $id
            ));
        }

        $alt = $this->eintraege[$id];

        $this->eintraege[$id] = static function (Container $container) use ($alt, $callable) {
            return $callable($alt($container), $container);
        };
    }

    /** @return array<int,string> Alle bekannten Schlüssel, in Registrierungsreihenfolge. */
    public function keys(): array
    {
        return array_keys($this->eintraege);
    }
}
