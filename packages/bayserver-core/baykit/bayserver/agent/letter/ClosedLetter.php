<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class ClosedLetter extends Letter
{
    public function __construct(int $stateId, Rudder $rd, Multiplexer $mpx)
    {
        parent::__construct($stateId, $rd, $mpx);
    }
}
