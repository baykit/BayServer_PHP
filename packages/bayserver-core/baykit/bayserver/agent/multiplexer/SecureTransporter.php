<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\ship\Ship;

class SecureTransporter extends PlainTransporter {

    private $sslCtx;


    public function __construct(Multiplexer $mpx, Ship $sip, bool $serverMode, int $bufSiz, bool $traceSsl, $sslCtx)
    {
        parent::__construct($mpx, $sip, $serverMode, $bufSiz, $traceSsl);
        $this->sslCtx = $sslCtx;
    }

    public function __toString(): string {
        return "stp[" . $this->ship . "]";
    }


    ////////////////////////////////////////////
    // Implements Transporter
    ////////////////////////////////////////////

    public function secure(): bool
    {
        return true;
    }

    ////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////

}









