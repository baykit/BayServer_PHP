<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

/**
 * A queued IO event. Mirrors the Letter : carries
 * (rudder, multiplexer); the dispatch loop reads the live RudderState
 * from $rudder->state, which is owned per-rudder by the multiplexer.
 */
abstract class Letter
{
    public Rudder $rudder;
    public Multiplexer $multiplexer;

    public function __construct(Rudder $rd, Multiplexer $mpx)
    {
        $this->rudder = $rd;
        $this->multiplexer = $mpx;
    }
}
