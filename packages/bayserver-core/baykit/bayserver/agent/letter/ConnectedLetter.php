<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class ConnectedLetter extends Letter
{
    public function __construct(RudderState $state)
    {
        parent::__construct($state);
    }
}