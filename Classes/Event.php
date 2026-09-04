<?php
/**
 * Created by PhpStorm.
 * User: ms
 * Date: 14.07.16
 * Time: 16:55
 */

namespace Areanet\PIM\Classes;


class Event extends \Symfony\Component\EventDispatcher\Event implements \Iterator
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