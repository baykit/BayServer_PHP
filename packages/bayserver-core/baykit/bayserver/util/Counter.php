<?php
namespace baykit\bayserver\util;

class Counter
{
    public $counter;

    public function __construct(int $ini=1)
    {
        $this->counter = $ini;
    }

    public function next() : int
    {
        $c = $this->counter;
        $this->counter += 1;
        return $c;
    }
}

