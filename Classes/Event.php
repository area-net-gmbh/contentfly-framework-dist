<?php
namespace Areanet\PIM\Classes;

/**
 * Das Ereignis, mit dem das Framework seine Hooks versorgt.
 *
 * ERBT SEIT 009-002-0004 VON `Symfony\Contracts\EventDispatcher\Event`. Die alte Basisklasse
 * `Symfony\Component\EventDispatcher\Event` gibt es in Symfony 7 nicht mehr — sie ist mit
 * Symfony 5 in die Contracts gewandert und in 6 aus der Komponente verschwunden. Der Wechsel
 * ist ein Namenswechsel: Die Klasse kann dasselbe, `stopPropagation()` eingeschlossen.
 *
 * Über dieses Objekt laufen die `pim.controller.before.*`- und `pim.file.*`-Hooks. Es traegt
 * beliebige benannte Parameter und laesst sich durchlaufen — daher `Iterator`.
 */
class Event extends \Symfony\Contracts\EventDispatcher\Event implements \Iterator
{
    protected $params = array();

    public function setParam($key, $object): void{
        $this->params[$key] = $object;
    }

    public function getParam($key){
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        return current($this->params);
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        return next($this->params);
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return key($this->params);
    }

    #[\ReturnTypeWillChange]
    public function valid()
    {
        return $this->current() !== false;
    }

    public function rewind(): void
    {
        reset($this->params);
    }


}