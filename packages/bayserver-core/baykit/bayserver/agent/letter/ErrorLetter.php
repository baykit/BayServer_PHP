<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class ErrorLetter extends Letter
{
    public \Throwable $err;

    public function __construct(Rudder $rd, Multiplexer $mpx, \Throwable $err)
    {
        parent::__construct($rd, $mpx);
        $this->err = $err;
    }
}
