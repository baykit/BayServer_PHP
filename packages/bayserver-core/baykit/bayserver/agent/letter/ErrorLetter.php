<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class ErrorLetter extends Letter
{
    public \Exception $err;

    public function __construct(RudderState $state, \Exception $err)
    {
        parent::__construct($state);
        $this->err = $err;
    }
}