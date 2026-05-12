<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;

class ReadLetter extends Letter
{
    public int $nBytes;
    public ?string $address;

    public function __construct(Rudder $rd, Multiplexer $mpx, int $n, ?string $adr)
    {
        parent::__construct($rd, $mpx);
        $this->nBytes = $n;
        $this->address = $adr;
    }
}
