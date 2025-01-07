<?php

namespace baykit\bayserver\agent\letter;

use baykit\bayserver\common\RudderState;

class ReadLetter extends Letter
{
    public int $nBytes;
    public ?string $address;

    public function __construct(RudderState $state, int $n, ?string $adr)
    {
        parent::__construct($state);
        $this->nBytes = $n;
        $this->address = $adr;
    }
}