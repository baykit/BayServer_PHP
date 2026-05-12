<?php

namespace baykit\bayserver\common;

use baykit\bayserver\docker\Barge;

/**
 * Collection of Barge dockers configured under a Harbor/City/Town.
Barges.
 */
class Barges
{
    /** Default barge docker (name == "*") */
    private ?Barge $anyBarge = null;

    /** @var Barge[] non-default barge dockers */
    public array $barges = [];

    public function add(Barge $b) : void
    {
        if ($b->name() === "*")
            $this->anyBarge = $b;
        else
            $this->barges[] = $b;
    }

    public function findBarge(string $path) : ?Barge
    {
        foreach ($this->barges as $b) {
            if ($this->match($b, $path))
                return $b;
        }
        return $this->anyBarge;
    }

    private function match(Barge $b, string $path) : bool
    {

        return true;
    }
}
