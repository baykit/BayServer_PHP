<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class ConnectedLetter extends Letter
{
    public function __construct(Rudder $rd, Multiplexer $mpx)
    {
        parent::__construct($rd, $mpx);
    }
}
