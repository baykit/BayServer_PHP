<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class Letter
{
    public RudderState $state;

    /**
     * @param RudderState $state
     */
    public function __construct(RudderState $state)
    {
        $this->state = $state;
    }

}