<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class AcceptedLetter extends Letter
{
    public Rudder $clientRudder;

    public function __construct(int $stateId, Rudder $rd, Multiplexer $mpx, Rudder $clientRd)
    {
        parent::__construct($stateId, $rd, $mpx);
        $this->clientRudder = $clientRd;
    }
}
