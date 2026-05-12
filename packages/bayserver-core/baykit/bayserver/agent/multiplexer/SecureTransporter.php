<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;

class SecureTransporter extends PlainTransporter {

    private $sslCtx;


    public function __construct(Multiplexer $mpx, Ship $sip, bool $serverMode, int $bufSiz, bool $traceSsl, $sslCtx)
    {
        parent::__construct($mpx, $sip, $serverMode, $bufSiz, $traceSsl);
        $this->sslCtx = $sslCtx;
    }

    public function __toString(): string {
        return "stp[". $this->ship. "]";
    }


    ////////////////////////////////////////////
    // Implements Transporter
    ////////////////////////////////////////////

    public function secure(): bool
    {
        return true;
    }

    public function reqTransfer(Rudder $rd, Rudder $fileRd, int $ofs, int $len, ?callable $callback): void {
        // sendfile() bypasses the TLS layer, so Direct Boarding does not
        // apply to secure transports. Mirrors the SecureTransporter
        // override (which also throws Sink).
        throw new Sink();
    }

    ////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////

}









