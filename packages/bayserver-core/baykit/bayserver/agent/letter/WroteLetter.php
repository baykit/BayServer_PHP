<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class WroteLetter extends Letter
{
    public int $nBytes;

    public function __construct(int $stateId, Rudder $rd, Multiplexer $mpx, int $n)
    {
        parent::__construct($stateId, $rd, $mpx);
        $this->nBytes = $n;
    }
}
