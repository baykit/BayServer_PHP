<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\Packet;

class H1Packet extends Packet {

    const MAX_HEADER_LEN = 0;  # H1 packet does not have packet header
    const MAX_DATA_LEN = 65536;

    # space
    const SP = " ";
    # Line separator
    const CRLF = "\r\n";

    public function __construct(int $type) {
        parent::__construct($type, H1Packet::MAX_HEADER_LEN, H1Packet::MAX_DATA_LEN);
    }

    public function __toString() : string
    {
        return "H1Packet({$this->type}) len={$this->dataLen()}";
    }
}