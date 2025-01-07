<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class ClosedLetter extends Letter
{
    public function __construct(RudderState $state)
    {
        parent::__construct($state);
    }
}