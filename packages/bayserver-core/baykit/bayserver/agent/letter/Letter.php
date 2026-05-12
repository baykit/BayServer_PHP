<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

/**
 * A queued IO event. Mirrors the Letter (post 0580a2b):
 * carries (stateId, rudder, multiplexer) so the dispatch loop can
 * look up the current RudderState fresh and reject stale letters
 * whose state has already been returned to the pool.
 */
abstract class Letter
{
    public int $stateId;
    public Rudder $rudder;
    public Multiplexer $multiplexer;

    public function __construct(int $stateId, Rudder $rd, Multiplexer $mpx)
    {
        $this->stateId = $stateId;
        $this->rudder = $rd;
        $this->multiplexer = $mpx;
    }
}
