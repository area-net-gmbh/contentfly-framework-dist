<?php
namespace Areanet\PIM\Classes;

/**
 * The event the framework feeds its hooks with.
 *
 * INHERITS FROM `Symfony\Contracts\EventDispatcher\Event` SINCE 009-002-0004. The old base class
 * `Symfony\Component\EventDispatcher\Event` no longer exists in Symfony 7 — it moved into the
 * Contracts with Symfony 5 and disappeared from the component in 6. The switch is a rename:
 * the class can do the same things, `stopPropagation()` included.
 *
 * The `pim.controller.before.*` and `pim.file.*` hooks run through this object. It carries
 * arbitrary named parameters and can be iterated — hence `Iterator`.
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