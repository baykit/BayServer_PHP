<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class WroteLetter extends Letter
{
    public int $nBytes;

    public function __construct(RudderState $state, int $n)
    {
        parent::__construct($state);
        $this->nBytes = $n;
    }
}