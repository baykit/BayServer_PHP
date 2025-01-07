<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;
use baykit\bayserver\rudder\Rudder;

class AcceptedLetter extends Letter
{
    public Rudder $clientRudder;

    public function __construct(RudderState $state, Rudder $rd)
    {
        parent::__construct($state);
        $this->clientRudder = $rd;
    }
}